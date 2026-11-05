<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\Skill;
use App\Models\User;

class ListSkills extends Tool
{
    public string $name = 'list_skills';

    public string $description = 'Skills (step-by-step instructions bots follow), with their mode and the tools they use.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => self::workspaceParam()]];
    }

    public function run(User $user, array $args): string
    {
        $ids = !empty($args['workspace']) ? [Lookup::workspace($user, $args['workspace'])->id]
            : Lookup::workspaces($user)->pluck('id')->all();
        $skills = Skill::where(fn ($q) => $q->whereIn('system_id', $ids)->orWhereNull('system_id'))->orderBy('name')->get();
        if ($skills->isEmpty()) {
            return 'No skills.';
        }

        return $skills->map(fn ($s) => "- {$s->name} ({$s->id}" . ($s->isBuiltIn() ? ', built in' : '') . '): '
            . ($s->mode ?: 'progressive') . '. ' . $s->description
            . ($s->tools ? ' Tools: ' . implode(', ', $s->tools) : ''))->implode("\n");
    }
}
