<?php

namespace Tests\Feature;

use App\Models\KbCollection;
use App\Models\KbSource;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RetrievalPlaygroundTest extends TestCase
{
    use RefreshDatabase;

    private System $system;

    protected function setUp(): void
    {
        parent::setUp();
        $this->system = System::create([
            'id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*',
        ]);
        KbCollection::create(['id' => 'kbc_1', 'system_id' => 'sys_test', 'name' => 'Refunds']);
        KbSource::create([
            'id' => 'kbs_1', 'collection_id' => 'kbc_1', 'type' => 'text',
            'title' => 'Refund policy', 'body' => 'Thirty days.', 'status' => 'ready',
        ]);
    }

    private function editor(): User
    {
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    public function test_the_playground_lists_the_workspace_collections(): void
    {
        $this->actingAs($this->editor())
            ->get(route('kb.playground'))
            ->assertOk()
            ->assertSee('Retrieval playground')
            ->assertSee('Refunds');
    }

    public function test_running_a_query_shows_the_matching_passages(): void
    {
        Http::fake(['*' => Http::response(['results' => [
            ['chunk_id' => 7, 'source_id' => 'kbs_1', 'content' => 'Thirty days.', 'score' => 0.0328],
        ]], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'how long do refunds take',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5,
                'candidates' => 30,
                'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Thirty days.')
            ->assertSee('Refund policy');   // the source title is resolved, not just its id
    }

    public function test_a_query_that_matches_nothing_says_so(): void
    {
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'unrelated question',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Nothing matched');
    }

    public function test_an_engine_failure_is_reported_not_swallowed(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'anything',
                'collections' => ['kbc_1'],
                'mode' => 'hybrid',
                'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk()
            ->assertSee('Engine returned HTTP 500');
    }

    public function test_a_query_is_required(): void
    {
        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'collections' => ['kbc_1'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertSessionHasErrors('query');
    }

    public function test_a_collection_from_another_workspace_is_ignored(): void
    {
        System::create(['id' => 'sys_other', 'name' => 'Other', 'allowed_origins' => '*']);
        KbCollection::create(['id' => 'kbc_other', 'system_id' => 'sys_other', 'name' => 'Theirs']);
        Http::fake(['*' => Http::response(['results' => []], 200)]);

        $this->actingAs($this->editor())
            ->post(route('kb.playground.run'), [
                'query' => 'x',
                'collections' => ['kbc_other'],
                'mode' => 'hybrid', 'top_k' => 5, 'candidates' => 30, 'min_score' => 0,
            ])
            ->assertOk();

        Http::assertSent(function ($request) {
            return $request['collection_ids'] === [];
        });
    }

    public function test_a_viewer_cannot_open_the_playground(): void
    {
        $viewer = User::create([
            'name' => 'Viewer', 'email' => 'viewer@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $viewer->systems()->attach('sys_test', ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('kb.playground'))->assertForbidden();
    }
}
