<?php

namespace Tests\Feature;

use App\Models\BotProfile;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\System;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatMessageReasoningTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): ChatConversation
    {
        System::create(['id' => 'sys_test', 'name' => 'Test Workspace', 'allowed_origins' => '*']);
        BotProfile::create(['id' => 'bot_1', 'system_id' => 'sys_test', 'name' => 'Bot']);

        return ChatConversation::create([
            'id' => 'conv_1',
            'bot_id' => 'bot_1',
            'session_id' => 'sess_1',
        ]);
    }

    public function test_an_answer_keeps_its_thinking_out_of_the_content(): void
    {
        $conversation = $this->makeConversation();

        ChatMessage::create([
            'id' => 'msg_1',
            'conversation_id' => $conversation->id,
            'sender' => 'assistant',
            'content' => 'The refund window is 30 days.',
            'reasoning' => 'The policy doc says 30 days, so answer plainly.',
        ]);

        $message = ChatMessage::find('msg_1');
        $this->assertSame('The refund window is 30 days.', $message->content);
        $this->assertSame('The policy doc says 30 days, so answer plainly.', $message->reasoning);
    }

    public function test_a_message_without_thinking_stores_no_reasoning(): void
    {
        $conversation = $this->makeConversation();

        ChatMessage::create([
            'id' => 'msg_2',
            'conversation_id' => $conversation->id,
            'sender' => 'user',
            'content' => 'Hello',
        ]);

        $this->assertTrue(Schema::hasColumn('chat_messages', 'reasoning'));
        $this->assertNull(ChatMessage::find('msg_2')->reasoning);
    }
}
