<?php

namespace App\Assistant\Tools;

use App\Assistant\Tool;
use App\Models\User;

class ListUsers extends Tool
{
    public string $name = 'list_users';

    public string $description = 'Every console user, their global role, and their workspaces.';

    public bool $superOnly = true;

    public function run(User $user, array $args): string
    {
        return User::with('systems')->orderBy('name')->limit(100)->get()
            ->map(fn ($u) => "- {$u->name} ({$u->email}): " . ($u->isSuperAdmin() ? 'super_admin' : 'user')
                . ($u->systems->isNotEmpty() ? '; ' . $u->systems->map(fn ($s) => "{$s->name} {$s->pivot->role}")->implode(', ') : ''))
            ->implode("\n");
    }
}
