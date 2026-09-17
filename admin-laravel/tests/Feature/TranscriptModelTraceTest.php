<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptModelTraceTest extends TestCase
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

    public function test_an_operator_can_see_which_model_did_each_job(): void
    {
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'assistant',
            'content' => 'One order is still pending.',
            'model_trace' => '{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('messages.0.model_trace', '{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}');
    }

    public function test_an_older_message_carries_no_trace(): void
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
            ->assertJsonPath('messages.0.model_trace', null);
    }

    /**
     * The transcript header names the models once for the whole session,
     * rather than under every answer.
     */
    public function test_the_session_lists_each_jobs_models_once(): void
    {
        $user = $this->editor();
        $this->conversation();

        ChatMessage::create([
            'id' => 'msg_1', 'conversation_id' => 'conv_1', 'sender' => 'user', 'content' => 'Hi',
        ]);
        ChatMessage::create([
            'id' => 'msg_2', 'conversation_id' => 'conv_1', 'sender' => 'assistant', 'content' => 'Hello',
            'model_trace' => '{"chat": "qwen3.5:4b", "intent": "qwen3:1.7b"}',
        ]);
        ChatMessage::create([
            'id' => 'msg_3', 'conversation_id' => 'conv_1', 'sender' => 'assistant', 'content' => 'Pending.',
            'model_trace' => '{"chat": "qwen3.5:4b", "sql": "qwen3-coder:30b"}',
        ]);
        ChatMessage::create([
            'id' => 'msg_4', 'conversation_id' => 'conv_1', 'sender' => 'assistant', 'content' => 'Broken trace',
            'model_trace' => 'not json',
        ]);

        $this->actingAs($user)
            ->get(route('logs.transcript', 'conv_1'))
            ->assertOk()
            ->assertJsonPath('session_id', 's1')
            ->assertJsonPath('message_count', 4)
            ->assertJsonPath('models', [
                'chat' => ['qwen3.5:4b'],
                'intent' => ['qwen3:1.7b'],
                'sql' => ['qwen3-coder:30b'],
            ]);
    }
}
