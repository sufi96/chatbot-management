<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\User;

class Whoami extends Tool
{
    public string $name = 'whoami';

    public string $description = 'Who I am talking to: their name, their role, and the workspaces they can reach.';

    public function run(User $user, array $args): string
    {
        if ($user->isSuperAdmin()) {
            return "{$user->name} ({$user->email}), super_admin: every workspace and the platform settings.";
        }
        $lines = Lookup::workspaces($user)->map(fn ($s) => "- {$s->name} ({$s->id}): " . $user->roleInSystem($s->id));

        return "{$user->name} ({$user->email}). Roles by workspace:\n" . $lines->implode("\n");
    }
}
