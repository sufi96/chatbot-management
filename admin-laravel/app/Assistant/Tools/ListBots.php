<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\User;

class ListBots extends Tool
{
    public string $name = 'list_bots';

    public string $description = 'The bots I can see: on or off, model, which sources are on, and whether files are on.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => self::workspaceParam()]];
    }

    public function run(User $user, array $args): string
    {
        $bots = Lookup::bots($user, $args['workspace'] ?? null);
        if ($bots->isEmpty()) {
            return 'No bots.';
        }
        $names = Lookup::workspaces($user)->pluck('name', 'id');

        return $bots->map(function ($bot) use ($names) {
            $files = $bot->fileSettings();
            $sources = GetBot::sources($bot);
            $on = collect($sources)->filter()->keys()->implode(', ') ?: 'none';

            return "- {$bot->name} ({$bot->id}), " . ($bot->is_platform ? 'console assistant' : 'workspace ' . ($names[$bot->system_id] ?? $bot->system_id))
                . ': ' . ($bot->is_active ? 'on' : 'off') . ', model ' . ($bot->model_name ?: 'not set')
                . ", sources on: {$on}"
                . ', files in ' . ($files['in_enabled'] ? 'on' : 'off') . ', files out ' . ($files['out_enabled'] ? 'on' : 'off');
        })->implode("\n");
    }
}
