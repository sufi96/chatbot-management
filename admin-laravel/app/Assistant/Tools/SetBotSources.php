<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\AnswerCache;
use App\Models\User;

class SetBotSources extends Tool
{
    public string $name = 'set_bot_sources';

    public string $description = 'Switch a bot\'s answer sources on or off: documents (knowledge base), database, tools, web. Only the ones given change.';

    public bool $writes = true;

    public string $minRole = 'editor';

    private const COLUMNS = [
        'documents' => 'retrieval_enabled',
        'database' => 'db_query_enabled',
        'tools' => 'agentic_enabled',
        'web' => 'web_search_enabled',
    ];

    public function parameters(): array
    {
        $flag = fn ($what) => ['type' => 'boolean', 'description' => "Switch {$what} on (true) or off (false)."];

        return ['type' => 'object', 'properties' => [
            'bot' => self::botParam(),
            'documents' => $flag('the knowledge base'),
            'database' => $flag('database answers'),
            'tools' => $flag('connector tools'),
            'web' => $flag('web search'),
        ], 'required' => ['bot']];
    }

    public function run(User $user, array $args): string
    {
        $bot = Lookup::bot($user, $args['bot'] ?? null, 'editor');
        $changes = [];
        foreach (self::COLUMNS as $source => $column) {
            $value = self::bool($args, $source);
            if ($value !== null) {
                $changes[$column] = $value;
            }
        }
        if (!$changes) {
            throw new Refusal('Nothing to change: name a source (documents, database, tools, web) and on or off.');
        }

        $bot->forceFill($changes)->save();
        // As the Behaviour page does: a different source can make a cached answer wrong.
        AnswerCache::forget($bot->id);

        return "{$bot->name} sources now: " . collect(GetBot::sources($bot->fresh()))
            ->map(fn ($v, $k) => "{$k}: " . ($v ? 'on' : 'off'))->implode(', ') . '.';
    }
}
