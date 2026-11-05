<?php

namespace App\Assistant\Tools;

use App\Assistant\Tool;
use App\Models\AssistantAction;
use App\Models\User;

class RecentAssistantActions extends Tool
{
    public string $name = 'recent_assistant_actions';

    public string $description = 'Changes made through this assistant: who, what, when, and whether it worked. A super admin sees everyone\'s; others see their own.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'days' => ['type' => 'integer', 'description' => 'How far back, in days. Default 7.'],
        ]];
    }

    public function run(User $user, array $args): string
    {
        $query = AssistantAction::query()->where('created_at', '>=', now()->subDays(self::days($args)))
            ->orderByDesc('id')->limit(30);
        if (!$user->isSuperAdmin()) {
            $query->where('user_id', $user->id);
        }
        $names = User::pluck('name', 'id');
        $actions = $query->get();

        return $actions->isEmpty() ? 'No changes made through the assistant in that time.'
            : $actions->map(fn ($a) => '- ' . $a->created_at?->diffForHumans() . ': ' . ($names[$a->user_id] ?? 'someone')
                . " ran {$a->tool} " . json_encode($a->arguments) . ' - ' . ($a->ok ? 'done' : 'refused')
                . ': ' . mb_strimwidth((string) $a->result, 0, 160, '…'))->implode("\n");
    }
}
