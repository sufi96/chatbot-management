<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\User;

class ListMembers extends Tool
{
    public string $name = 'list_members';

    public string $description = "A workspace's people and their roles.";

    public string $minRole = 'system_admin';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => ['type' => 'string', 'description' => 'The workspace, by id or name.']],
            'required' => ['workspace']];
    }

    public function run(User $user, array $args): string
    {
        $system = Lookup::workspace($user, $args['workspace'] ?? null, 'system_admin');
        $members = $system->users()->orderBy('name')->get();

        return $members->isEmpty() ? "No members in {$system->name}."
            : "{$system->name}:\n" . $members->map(fn ($u) => "- {$u->name} ({$u->email}): {$u->pivot->role}")->implode("\n");
    }
}
