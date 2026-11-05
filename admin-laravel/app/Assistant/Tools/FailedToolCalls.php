<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\ChatToolCall;
use App\Models\User;

class FailedToolCalls extends Tool
{
    public string $name = 'failed_tool_calls';

    public string $description = 'Tool calls that failed recently: which bot, which tool, and the error.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'bot' => ['type' => 'string', 'description' => 'Only this bot, by id or name.'],
            'days' => ['type' => 'integer', 'description' => 'How far back, in days. Default 7.'],
        ]];
    }

    public function run(User $user, array $args): string
    {
        $bots = !empty($args['bot']) ? collect([Lookup::bot($user, $args['bot'])]) : Lookup::bots($user);
        $names = $bots->pluck('name', 'id');

        $calls = ChatToolCall::query()
            ->join('chat_conversations', 'chat_conversations.id', '=', 'chat_tool_calls.conversation_id')
            ->whereIn('chat_conversations.bot_id', $names->keys())
            ->where('chat_tool_calls.status', 'failed')
            ->where('chat_tool_calls.created_at', '>=', now()->subDays(self::days($args)))
            ->orderByDesc('chat_tool_calls.created_at')->limit(20)
            ->get(['chat_tool_calls.*', 'chat_conversations.bot_id']);
        if ($calls->isEmpty()) {
            return 'No failed tool calls in that time.';
        }

        return $calls->map(fn ($c) => "- " . ($names[$c->bot_id] ?? $c->bot_id) . ": {$c->tool_name}, "
            . $c->created_at?->diffForHumans() . ', conversation ' . $c->conversation_id
            . ($c->result_excerpt ? ': ' . mb_strimwidth($c->result_excerpt, 0, 200, '…') : ''))->implode("\n");
    }
}
