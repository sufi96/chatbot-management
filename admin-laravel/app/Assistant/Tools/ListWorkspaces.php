<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\BotProfile;
use App\Models\User;

class ListWorkspaces extends Tool
{
    public string $name = 'list_workspaces';

    public string $description = 'The workspaces I can reach, with how many bots each has.';

    public function run(User $user, array $args): string
    {
        $workspaces = Lookup::workspaces($user);
        if ($workspaces->isEmpty()) {
            return 'No workspaces.';
        }
        $counts = BotProfile::whereIn('system_id', $workspaces->pluck('id'))
            ->selectRaw('system_id, count(*) as n')->groupBy('system_id')->pluck('n', 'system_id');

        return $workspaces->map(fn ($s) => "- {$s->name} ({$s->id}): " . ($counts[$s->id] ?? 0) . ' bots'
            . ($user->isSuperAdmin() ? '' : ', my role ' . $user->roleInSystem($s->id)))->implode("\n");
    }
}
