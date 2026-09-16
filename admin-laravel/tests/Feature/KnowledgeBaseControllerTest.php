<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeBaseControllerTest extends TestCase
{
    use RefreshDatabase;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::create([
            'name' => 'Tester', 'email' => $role . '@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($this->system->id, ['role' => $role]);

        return $user;
    }

    public function test_an_editor_sees_the_collection_list(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->get(route('kb.index'))
            ->assertOk()
            ->assertSee('Knowledge base');
    }

    public function test_an_editor_can_create_a_collection(): void
    {
        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.store'), ['name' => 'Refund policy'])
            ->assertRedirect();

        $this->assertDatabaseHas('kb_collections', [
            'name' => 'Refund policy', 'system_id' => 'sys_test',
        ]);
    }

    public function test_a_viewer_cannot_create_a_collection(): void
    {
        $this->actingAs($this->userWithRole('viewer'))
            ->post(route('kb.store'), ['name' => 'Nope'])
            ->assertForbidden();

        $this->assertDatabaseCount('kb_collections', 0);
    }

    public function test_adding_a_text_source_queues_indexing(): void
    {
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.sources.store', 'kbc_1'), [
                'type' => 'text',
                'title' => 'Policy',
                'body' => 'Refunds within thirty days.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('kb_sources', ['title' => 'Policy', 'type' => 'text']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/index'));
    }

    public function test_a_qa_source_requires_both_halves(): void
    {
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);

        $this->actingAs($this->userWithRole('editor'))
            ->post(route('kb.sources.store', 'kbc_1'), ['type' => 'qa', 'title' => 'Question only'])
            ->assertSessionHasErrors('body');
    }

    public function test_a_collection_from_another_workspace_is_refused(): void
    {
        $other = System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->userWithRole('editor'))
            ->get(route('kb.show', 'kbc_other'))
            ->assertForbidden();
    }

    public function test_removing_a_source_asks_the_engine_to_drop_its_chunks(): void
    {
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'C']);
        \App\Models\KbSource::create([
            'id' => 'kbs_1', 'collection_id' => 'kbc_1',
            'type' => 'text', 'title' => 'T', 'body' => 'B',
        ]);

        $this->actingAs($this->userWithRole('editor'))
            ->delete(route('kb.sources.destroy', 'kbs_1'))
            ->assertRedirect();

        $this->assertDatabaseCount('kb_sources', 0);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/chunks'));
    }
}
