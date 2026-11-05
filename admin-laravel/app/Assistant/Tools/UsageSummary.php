<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

class UsageSummary extends Tool
{
    public string $name = 'usage_summary';

    public string $description = 'Per bot over recent days: conversations, answers, and where the answers came from (documents, database, tools, web, model, none, refused).';

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
        $since = now()->subDays($days = self::days($args));

        $lines = $bots->map(function ($bot) use ($since) {
            $conversations = ChatConversation::where('bot_id', $bot->id)->where('updated_at', '>=', $since)->count();
            $kinds = ChatMessage::join('chat_conversations', 'chat_conversations.id', '=', 'chat_messages.conversation_id')
                ->where('chat_conversations.bot_id', $bot->id)->where('chat_messages.sender', 'assistant')
                ->where('chat_messages.created_at', '>=', $since)
                ->selectRaw("coalesce(chat_messages.source_kind, 'unknown') as kind, count(*) as n")
                ->groupBy('kind')->pluck('n', 'kind');
            if ($conversations === 0 && $kinds->isEmpty()) {
                return null;
            }

            return "- {$bot->name}: {$conversations} conversation" . ($conversations === 1 ? '' : 's') . ', '
                . $kinds->sum() . ' answers (' . $kinds->map(fn ($n, $k) => "{$k} {$n}")->implode(', ') . ')';
        })->filter();

        return $lines->isEmpty() ? "No conversations in the last {$days} days." : "Last {$days} days:\n" . $lines->implode("\n");
    }
}
