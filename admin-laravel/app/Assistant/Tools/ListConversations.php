<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\ChatConversation;
use App\Models\User;

class ListConversations extends Tool
{
    public string $name = 'list_conversations';

    public string $description = 'Recent conversations, newest first. Can keep only those waiting for a person, or those with a flagged message.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'bot' => ['type' => 'string', 'description' => 'Only this bot, by id or name.'],
            'waiting_for_person' => ['type' => 'boolean', 'description' => 'Only those where the bot asked staff to follow up.'],
            'flagged' => ['type' => 'boolean', 'description' => 'Only those with a message the guard flagged.'],
            'days' => ['type' => 'integer', 'description' => 'How far back, in days. Default 7.'],
        ]];
    }

    public function run(User $user, array $args): string
    {
        $botIds = !empty($args['bot']) ? [Lookup::bot($user, $args['bot'])->id] : Lookup::bots($user)->pluck('id')->all();
        $query = ChatConversation::with('bot')->whereIn('bot_id', $botIds)
            ->where('updated_at', '>=', now()->subDays(self::days($args)))
            ->withCount('messages')->orderByDesc('updated_at')->limit(20);
        if (self::bool($args, 'waiting_for_person')) {
            $query->whereNotNull('handover_requested_at');
        }
        if (self::bool($args, 'flagged')) {
            $query->whereHas('messages', fn ($q) => $q->whereNotNull('guard_flag'));
        }
        $found = $query->get();
        if ($found->isEmpty()) {
            return 'No conversations match.';
        }

        return $found->map(fn ($c) => "- {$c->id}: " . ($c->bot->name ?? $c->bot_id) . ", {$c->messages_count} messages, last "
            . $c->updated_at?->diffForHumans()
            . ($c->handover_requested_at ? ', waiting for a person' . ($c->handover_reason ? " ({$c->handover_reason})" : '') : '')
        )->implode("\n");
    }
}
