<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeBaseUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);
    }

    /** Reused within a test, so it must not create a second row. */
    private function editor(): User
    {
        $existing = User::where('email', 'editor@test.com')->first();
        if ($existing) {
            return $existing;
        }

        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    public function test_uploading_a_file_stores_it_and_queues_indexing(): void
    {
        $file = UploadedFile::fake()->createWithContent('policy.txt', 'Refunds in thirty days.');

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertRedirect();

        $source = KbSource::first();
        $this->assertSame('file', $source->type);
        $this->assertSame('policy.txt', $source->title);
        $this->assertNotNull($source->file_path);
        Storage::disk('public')->assertExists($source->file_path);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_an_unsupported_type_is_refused(): void
    {
        $file = UploadedFile::fake()->create('nasty.exe', 10);

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('kb_sources', 0);
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        $file = UploadedFile::fake()->create('huge.pdf', 25000, 'application/pdf');

        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_a_viewer_cannot_upload(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->post(route('kb.sources.upload', 'kbc_1'),
                ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')])
            ->assertForbidden();
    }

    public function test_deleting_a_file_source_removes_the_stored_file(): void
    {
        $file = UploadedFile::fake()->createWithContent('policy.txt', 'Refunds.');
        $this->actingAs($this->editor())
            ->post(route('kb.sources.upload', 'kbc_1'), ['file' => $file]);

        $source = KbSource::first();
        $path = $source->file_path;

        $this->actingAs($this->editor())
            ->delete(route('kb.sources.destroy', $source->id))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }
}
