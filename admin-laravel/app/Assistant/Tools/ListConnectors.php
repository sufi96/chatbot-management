<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\Connector;
use App\Models\User;

class ListConnectors extends Tool
{
    public string $name = 'list_connectors';

    public string $description = 'Connectors (REST and MCP systems bots can call) and their tools, each on or off. Never shows keys.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => self::workspaceParam()]];
    }

    public function run(User $user, array $args): string
    {
        $ids = Lookup::workspaceIds($user, $args['workspace'] ?? null);
        $connectors = Connector::with('tools')->whereIn('system_id', $ids)->orderBy('name')->get();
        if ($connectors->isEmpty()) {
            return 'No connectors.';
        }

        return $connectors->map(fn ($c) => "- {$c->name} ({$c->type}, {$c->id})\n"
            . $c->tools->map(fn ($t) => "  - {$t->name} ({$t->id}): " . ($t->enabled ? 'on' : 'off')
                . ($t->writes ? ', changes things' : ''))->implode("\n"))->implode("\n");
    }
}
