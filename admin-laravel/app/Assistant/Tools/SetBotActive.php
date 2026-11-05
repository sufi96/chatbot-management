<?php

namespace App\Assistant\Tools;

use App\Assistant\Lookup;
use App\Assistant\Refusal;
use App\Assistant\Tool;
use App\Models\User;

class SetBotActive extends Tool
{
    public string $name = 'set_bot_active';

    public string $description = 'Switch a bot on or off. Off: every site running it stops answering.';

    public bool $writes = true;

    public string $minRole = 'editor';

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'bot' => self::botParam(),
            'active' => ['type' => 'boolean', 'description' => 'true to switch on, false to switch off.'],
        ], 'required' => ['bot', 'active']];
    }

    public function run(User $user, array $args): string
    {
        $bot = Lookup::bot($user, $args['bot'] ?? null, 'editor');
        $active = self::bool($args, 'active') ?? throw new Refusal('Say whether to switch it on or off.');

        $bot->forceFill(['is_active' => $active])->save();

        return "{$bot->name} is now " . ($active ? 'on' : 'off') . '.';
    }
}
