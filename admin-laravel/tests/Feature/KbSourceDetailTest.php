<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KbSourceDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        System::create(['id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'Refunds']);
        KbSource::create([
            'id' => 'kbs_text', 'collection_id' => 'kbc_1', 'type' => 'text',
            'title' => 'Refund policy', 'description' => 'Retail terms.',
            'body' => 'Thirty days.', 'status' => 'ready', 'chunk_count' => 1,
        ]);
        DB::table('kb_chunks')->insert([
            'collection_id' => 'kbc_1', 'source_id' => 'kbs_text', 'ordinal' => 0,
            'content' => "Section: Refund policy\nAbout: Retail terms.\n\nThirty days.",
            'char_count' => 58, 'heading_path' => 'Refund policy',
        ]);
    }

    private function user(string $role, string $email): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => ucfirst($role), 'password' => bcrypt('password'), 'global_role' => 'user'],
        );
        $user->systems()->syncWithoutDetaching(['sys_test' => ['role' => $role]]);

        return $user;
    }

    public function test_the_detail_page_shows_the_source_and_its_chunks(): void
    {
        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_text'))
            ->assertOk()
            ->assertSee('Refund policy')
            ->assertSee('Retail terms.')
            ->assertSee('Thirty days.');
    }

    public function test_a_viewer_can_read_a_source_but_cannot_save_it(): void
    {
        $viewer = $this->user('viewer', 'viewer@test.com');

        $this->actingAs($viewer)
            ->get(route('kb.sources.show', 'kbs_text'))
            ->assertOk();

        $this->actingAs($viewer)
            ->put(route('kb.sources.update', 'kbs_text'),
                ['title' => 'Changed', 'body' => 'Changed'])
            ->assertForbidden();
    }

    public function test_editing_a_text_source_saves_and_queues_a_reindex(): void
    {
        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->put(route('kb.sources.update', 'kbs_text'), [
                'title' => 'Refund policy v2',
                'description' => 'Updated retail terms.',
                'body' => 'Forty five days.',
            ])
            ->assertRedirect(route('kb.sources.show', 'kbs_text'));

        $source = KbSource::find('kbs_text');
        $this->assertSame('Refund policy v2', $source->title);
        $this->assertSame('Updated retail terms.', $source->description);
        $this->assertSame('Forty five days.', $source->body);
        $this->assertSame('pending', $source->status);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_a_file_source_has_no_editable_body(): void
    {
        KbSource::create([
            'id' => 'kbs_file', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 2048, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_file'))
            ->assertOk()
            ->assertSee('terms.pdf')
            ->assertDontSee('name="body"', false);
    }

    public function test_editing_a_file_source_needs_no_body(): void
    {
        KbSource::create([
            'id' => 'kbs_file2', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 2048, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->put(route('kb.sources.update', 'kbs_file2'), [
                'title' => 'terms.pdf', 'description' => 'Supplier contract terms.',
            ])
            ->assertRedirect();

        $this->assertSame('Supplier contract terms.',
            KbSource::find('kbs_file2')->description);
    }

    public function test_downloading_a_text_source_returns_markdown(): void
    {
        $response = $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.download', 'kbs_text'))
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringContainsString('# Refund policy', $body);
        $this->assertStringContainsString('Retail terms.', $body);
        $this->assertStringContainsString('Thirty days.', $body);
    }

    public function test_downloading_a_file_source_returns_the_stored_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('kb/sources/terms.pdf', '%PDF-1.4 fake');
        KbSource::create([
            'id' => 'kbs_file3', 'collection_id' => 'kbc_1', 'type' => 'file',
            'title' => 'terms.pdf', 'file_path' => 'kb/sources/terms.pdf',
            'file_mime' => 'application/pdf', 'file_size' => 13, 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.download', 'kbs_file3'))
            ->assertOk()
            ->assertDownload('terms.pdf');
    }

    public function test_a_source_in_another_workspace_is_refused(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);
        KbSource::create([
            'id' => 'kbs_other', 'collection_id' => 'kbc_other', 'type' => 'text',
            'title' => 'Secret', 'body' => 'x', 'status' => 'ready',
        ]);

        $this->actingAs($this->user('editor', 'editor@test.com'))
            ->get(route('kb.sources.show', 'kbs_other'))
            ->assertForbidden();
    }
}
