<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\ConnectorTool;
use App\Models\User;

class SetConnectorToolEnabled extends Tool
{
    public string $name = 'set_connector_tool_enabled';

    public string $description = 'Switch one connector tool on or off for every bot that uses it. Named by id or name, from list_connectors.';

    public bool $writes = true;

    public string $minRole = 'system_admin';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'tool_id' => ['type' => 'string', 'description' => 'The tool, by id or name.'],
            'enabled' => ['type' => 'boolean'],
        ], 'required' => ['tool_id', 'enabled']];
    }

    public function run(User $user, array $args): string
    {
        $ref = (string) ($args['tool_id'] ?? '');
        $enabled = self::bool($args, 'enabled') ?? throw new Refusal('Say whether to switch it on or off.');
        $found = ConnectorTool::with('connector')
            ->whereHas('connector', fn ($q) => $q->whereIn('system_id', Lookup::workspaces($user, 'system_admin')->pluck('id')))
            ->where(fn ($q) => $q->where('id', $ref)->orWhere('name', $ref))->get();
        if ($found->isEmpty()) {
            throw new Refusal("No connector tool {$ref} that you can change.");
        }
        if ($found->count() > 1) {
            throw new Refusal("More than one tool called {$ref}: " . $found->map(fn ($t) => "{$t->id} on {$t->connector->name}")->implode('; ') . '. Say which id.');
        }

        $tool = $found->first();
        $tool->update(['enabled' => $enabled]);

        return "{$tool->name} on {$tool->connector->name} is now " . ($enabled ? 'on' : 'off') . '.';
    }
}
