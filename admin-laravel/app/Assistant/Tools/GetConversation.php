<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\ChatConversation;
use App\Models\User;

class GetConversation extends Tool
{
    public string $name = 'get_conversation';

    public string $description = "One conversation's transcript, with its tool calls and files.";

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'conversation_id' => ['type' => 'string', 'description' => 'The id from list_conversations.'],
        ], 'required' => ['conversation_id']];
    }

    public function run(User $user, array $args): string
    {
        $id = (string) ($args['conversation_id'] ?? '');
        $conversation = ChatConversation::with(['messages.toolCalls', 'messages.files'])
            ->whereIn('bot_id', Lookup::bots($user)->pluck('id'))->find($id);
        if (!$conversation) {
            throw new Refusal("No conversation {$id} that you can reach.");
        }

        $lines = $conversation->messages->sortBy('created_at')->take(40)->map(function ($m) {
            $line = ($m->sender === 'user' ? 'Visitor' : 'Bot') . ': ' . mb_strimwidth((string) $m->content, 0, 600, '…');
            foreach ($m->toolCalls as $call) {
                $line .= "\n  tool {$call->tool_name}: {$call->status}";
            }
            foreach ($m->files as $file) {
                $line .= "\n  file {$file->filename} ({$file->direction})";
            }
            if ($m->guard_flag) {
                $line .= "\n  flagged: {$m->guard_flag}";
            }

            return $line;
        });

        return "Conversation {$conversation->id}:\n" . $lines->implode("\n");
    }
}
