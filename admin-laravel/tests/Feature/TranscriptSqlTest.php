<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptSqlTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        System::create(['id' => 'sys_test', 'name' => 'W', 'allowed_origins' => '*']);
        $user = User::create([
            'name' => 'Editor', 'email' => 'editor@example.test',
            'password' => 'password', 'global_role' => 'user',
        ]);
        $user->systems()->attach('sys_test', ['role' => 'editor']);

        return $user;
    }

    private function conversation(): void
    {
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Bot']);
        ChatConversation::create([
            'id' => 'conv_1', 'bot_id' => 'bot_1', 'session_id' => 's1', 'origin' => '',
        ]);
    }

    public function test_an_operator_can_see_the_statement_that_answered(): void
    {
        // Auditing a wrong answer needs the query, not a guess at it.
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'One order is still pending.',
            'db_sql' => 'SELECT id, status FROM orders LIMIT 50',
            'db_row_count' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.db_sql', 'SELECT id, status FROM orders LIMIT 50')
            ->assertJsonPath('messages.0.db_row_count', 1);
    }

    public function test_a_message_the_database_did_not_answer_carries_no_statement(): void
    {
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_2', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'Refunds are within 30 days.',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.db_sql', null);
    }
}
