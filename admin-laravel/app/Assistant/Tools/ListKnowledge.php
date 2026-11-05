<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\KbCollection;
use App\Models\User;

class ListKnowledge extends Tool
{
    public string $name = 'list_knowledge';

    public string $description = 'Knowledge collections and their sources, with each source\'s status. Sources that failed to index show their error.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => self::workspaceParam()]];
    }

    public function run(User $user, array $args): string
    {
        $ids = Lookup::workspaceIds($user, $args['workspace'] ?? null);
        $collections = KbCollection::with('sources')->whereIn('system_id', $ids)->orderBy('name')->get();
        if ($collections->isEmpty()) {
            return 'No knowledge collections.';
        }

        return $collections->map(function ($c) {
            $sources = $c->sources->take(30)->map(fn ($s) => "  - {$s->title} ({$s->id}): {$s->status}"
                . ($s->status === 'ready' ? ", {$s->chunk_count} chunks" : '')
                . ($s->error_message ? " - {$s->error_message}" : ''));

            return "- {$c->name} ({$c->id}), {$c->sources->count()} sources\n" . $sources->implode("\n");
        })->implode("\n");
    }
}
