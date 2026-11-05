<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\BotProfile;
use App\Models\User;
use App\Support\SourceOrder;

class GetBot extends Tool
{
    public string $name = 'get_bot';

    public string $description = "One bot's settings: model, sources and their order, knowledge, tools, skills, files, guard and voice.";

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['bot' => self::botParam()], 'required' => ['bot']];
    }

    /** Each answer source and whether it is on, in the bot's order. */
    public static function sources(BotProfile $bot): array
    {
        $on = [
            'documents' => (bool) $bot->retrieval_enabled,
            'database' => (bool) $bot->db_query_enabled,
            'tools' => (bool) $bot->agentic_enabled,
            'web' => (bool) $bot->web_search_enabled,
        ];
        $order = explode(',', SourceOrder::normalise($bot->source_order));

        return collect($order)->mapWithKeys(fn ($s) => [$s => $on[$s] ?? false])->all();
    }

    public function run(User $user, array $args): string
    {
        $bot = Lookup::bot($user, $args['bot'] ?? null);
        $files = $bot->fileSettings();
        $yes = fn ($v) => $v ? 'on' : 'off';

        $lines = [
            "{$bot->name} ({$bot->id}): " . $yes($bot->is_active) . ', model ' . ($bot->model_name ?: 'not set'),
            'Sources in order: ' . collect(self::sources($bot))->map(fn ($v, $k) => "{$k}: " . $yes($v))->implode(', '),
            'Combine sources: ' . $yes($bot->combine_sources) . '. When nothing is found: ' . ($bot->retrieval_fallback ?: 'say_unknown'),
            'Knowledge collections: ' . ($bot->collections()->pluck('name')->implode(', ') ?: 'none'),
            'Database connections: ' . ($bot->dbConnections()->pluck('name')->implode(', ') ?: 'none'),
            'Connector tools: ' . ($bot->tools()->pluck('name')->implode(', ') ?: 'none')
                . '. Built-in tools: ' . (implode(', ', (array) $bot->builtin_tools) ?: 'none'),
            'Skills: ' . ($bot->skills()->pluck('name')->implode(', ') ?: 'none'),
            'Files in: ' . $yes($files['in_enabled']) . ' (' . implode(', ', $files['in_types']) . "; up to {$files['max_mb']} MB, "
                . "{$files['per_message']} per message, {$files['daily']} a day)",
            'Files out: ' . $yes($files['out_enabled']) . ' (' . implode(', ', $files['out_formats']) . "; {$files['out_mode']}). "
                . 'Visuals: ' . $yes($files['visuals']) . ". Files kept {$files['retention_days']} days.",
            'Guard: ' . $yes($bot->guard_enabled) . '. Intent: ' . $yes($bot->intent_enabled)
                . '. Answer cache: ' . $yes($bot->cache_enabled) . '. Grounding check: ' . $yes($bot->grounding_check),
            'Voice out: ' . $yes($bot->voice_output) . ', voice in: ' . $yes($bot->voice_input),
            'Prompt: ' . mb_strimwidth((string) $bot->system_prompt, 0, 400, '…'),
        ];

        return implode("\n", $lines);
    }
}
