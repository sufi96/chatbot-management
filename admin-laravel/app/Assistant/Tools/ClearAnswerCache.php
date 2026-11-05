<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Tool;
use App\Models\AnswerCache;
use App\Models\User;

class ClearAnswerCache extends Tool
{
    public string $name = 'clear_answer_cache';

    public string $description = "Drop a bot's cached answers, so every question is answered afresh.";

    public bool $writes = true;

    public string $minRole = 'editor';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['bot' => self::botParam()], 'required' => ['bot']];
    }

    public function run(User $user, array $args): string
    {
        $bot = Lookup::bot($user, $args['bot'] ?? null, 'editor');
        $dropped = AnswerCache::forget($bot->id);

        return "Cleared {$dropped} cached answer" . ($dropped === 1 ? '' : 's') . " for {$bot->name}.";
    }
}
