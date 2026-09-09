<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'name' => 'Root', 'email' => 'root@test.com',
            'password' => bcrypt('password'), 'global_role' => 'super_admin',
        ]);
    }

    private function systemAdmin(): User
    {
        $system = System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Admin', 'email' => 'admin@test.com',
            'password' => bcrypt('password'), 'global_role' => 'user',
        ]);
        $user->systems()->attach($system->id, ['role' => 'system_admin']);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'embedding_base_url' => 'http://localhost:11434/v1',
            'embedding_api_key' => '',
            'embedding_model' => 'nomic-embed-text',
            'embedding_dimensions' => 768,
            'vector_driver' => 'pgvector',
            'chunk_size' => 900,
            'chunk_overlap' => 150,
        ], $overrides);
    }

    public function test_a_super_admin_sees_the_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Embedding')
            ->assertSee('nomic-embed-text');
    }

    public function test_a_system_admin_is_refused(): void
    {
        $this->actingAs($this->systemAdmin())
            ->get(route('admin.settings'))
            ->assertForbidden();
    }

    public function test_saving_stores_the_settings(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload([
                'embedding_model' => 'mxbai-embed-large',
                'embedding_dimensions' => 1024,
                'chunk_size' => 800,
                'chunk_overlap' => 100,
            ]))
            ->assertRedirect();

        $this->assertSame('mxbai-embed-large', AppSetting::get('embedding_model'));
        $this->assertSame('800', AppSetting::get('chunk_size'));
    }

    public function test_overlap_must_be_smaller_than_chunk_size(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'),
                $this->payload(['chunk_size' => 500, 'chunk_overlap' => 500]))
            ->assertSessionHasErrors('chunk_overlap');
    }

    public function test_an_unknown_vector_driver_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.settings.update'), $this->payload(['vector_driver' => 'pinecone']))
            ->assertSessionHasErrors('vector_driver');
    }

    public function test_the_test_button_reports_the_engine_answer(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'dimensions' => 768,
                                           'message' => 'Answered with 768 dimensions.'], 200)]);

        $this->actingAs($this->superAdmin())
            ->post(route('admin.settings.test'), [
                'embedding_base_url' => 'http://localhost:11434/v1',
                'embedding_api_key' => '',
                'embedding_model' => 'nomic-embed-text',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'dimensions' => 768]);
    }
}
