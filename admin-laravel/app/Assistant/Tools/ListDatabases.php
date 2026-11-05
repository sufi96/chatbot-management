<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\DbConnection;
use App\Models\User;

class ListDatabases extends Tool
{
    public string $name = 'list_databases';

    public string $description = 'Database connections bots can read, with status and readable tables. Never shows credentials.';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['workspace' => self::workspaceParam()]];
    }

    public function run(User $user, array $args): string
    {
        $ids = !empty($args['workspace']) ? [Lookup::workspace($user, $args['workspace'])->id]
            : Lookup::workspaces($user)->pluck('id')->all();
        $connections = DbConnection::with('tables')->whereIn('system_id', $ids)->orderBy('name')->get();
        if ($connections->isEmpty()) {
            return 'No database connections.';
        }

        return $connections->map(fn ($c) => "- {$c->name} ({$c->driver}, {$c->id}): " . ($c->is_enabled ? 'on' : 'off')
            . ', status ' . ($c->status ?: 'unknown') . ($c->error_message ? " - {$c->error_message}" : '')
            . '. Readable tables: ' . ($c->tables->where('is_enabled', true)->pluck('table_name')->implode(', ') ?: 'none'))
            ->implode("\n");
    }
}
