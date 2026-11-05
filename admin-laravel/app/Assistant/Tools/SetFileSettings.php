<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\BotProfile;
use App\Models\User;

class SetFileSettings extends Tool
{
    public string $name = 'set_file_settings';

    public string $description = 'Change a bot\'s files and visuals: files in (visitors attach files), files out (the bot hands back files), allowed types, limits, retention, drawn visuals. Only the ones given change.';

    public bool $writes = true;

    public string $minRole = 'editor';

    /** Argument => [setting, lowest, highest], the same limits as the Behaviour page. */
    private const NUMBERS = [
        'max_mb' => ['max_mb', 1, 50],
        'per_message' => ['per_message', 1, 10],
        'daily' => ['daily', 1, 500],
        'retention_days' => ['retention_days', 1, 365],
    ];

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'bot' => self::botParam(),
            'files_in' => ['type' => 'boolean'],
            'in_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => BotProfile::FILE_IN_TYPES]],
            'max_mb' => ['type' => 'integer', 'description' => 'Largest file, 1 to 50 MB.'],
            'per_message' => ['type' => 'integer', 'description' => 'Files per message, 1 to 10.'],
            'daily' => ['type' => 'integer', 'description' => 'Files per visitor a day, 1 to 500.'],
            'files_out' => ['type' => 'boolean'],
            'out_formats' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => BotProfile::FILE_OUT_FORMATS]],
            'out_mode' => ['type' => 'string', 'enum' => ['on_request', 'own_initiative']],
            'visuals' => ['type' => 'boolean', 'description' => 'Charts, diagrams and drawings in answers.'],
            'retention_days' => ['type' => 'integer', 'description' => 'Days files are kept, 1 to 365.'],
        ], 'required' => ['bot']];
    }

    public function run(User $user, array $args): string
    {
        $bot = Lookup::bot($user, $args['bot'] ?? null, 'editor');
        $files = $bot->fileSettings();
        $before = $files;

        foreach (['files_in' => 'in_enabled', 'files_out' => 'out_enabled', 'visuals' => 'visuals'] as $arg => $key) {
            if (($value = self::bool($args, $arg)) !== null) {
                $files[$key] = $value;
            }
        }
        foreach (['in_types' => BotProfile::FILE_IN_TYPES, 'out_formats' => BotProfile::FILE_OUT_FORMATS] as $key => $allowed) {
            if (isset($args[$key])) {
                $asked = array_map(fn ($v) => strtolower((string) $v), (array) $args[$key]);
                $files[$key] = array_values(array_intersect($allowed, $asked));
            }
        }
        foreach (self::NUMBERS as $arg => [$key, $low, $high]) {
            if (isset($args[$arg])) {
                $files[$key] = max($low, min($high, (int) $args[$arg]));
            }
        }
        if (isset($args['out_mode'])) {
            $files['out_mode'] = in_array($args['out_mode'], ['on_request', 'own_initiative'], true)
                ? $args['out_mode'] : throw new Refusal('out_mode must be on_request or own_initiative.');
        }
        if ($files === $before) {
            throw new Refusal('Nothing to change: name a file setting and its new value.');
        }

        $bot->forceFill(['file_settings' => $files])->save();

        return "{$bot->name} files now: in " . ($files['in_enabled'] ? 'on' : 'off') . ' (' . implode(', ', $files['in_types'])
            . ", up to {$files['max_mb']} MB), out " . ($files['out_enabled'] ? 'on' : 'off') . ' (' . implode(', ', $files['out_formats'])
            . '), visuals ' . ($files['visuals'] ? 'on' : 'off') . ", kept {$files['retention_days']} days.";
    }
}
