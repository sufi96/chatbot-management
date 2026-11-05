<?php

namespace App\Assistant;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every tool the console assistant has. A new console feature adds its tools
 * here, and its routes to Coverage; the coverage test holds both to it.
 */
class Registry
{
    public const TOOLS = [
        // Reading and reporting.
        Tools\Whoami::class,
        Tools\ListWorkspaces::class,
        Tools\ListBots::class,
        Tools\GetBot::class,
        Tools\ListConversations::class,
        Tools\GetConversation::class,
        Tools\FailedToolCalls::class,
        Tools\UsageSummary::class,
        Tools\ListKnowledge::class,
        Tools\ListConnectors::class,
        Tools\ListSkills::class,
        Tools\ListDatabases::class,
        Tools\ListMembers::class,
        Tools\ListUsers::class,
        Tools\RecentAssistantActions::class,
        // Changes, each behind Confirm.
        Tools\SetBotActive::class,
        Tools\SetBotSources::class,
        Tools\SetFileSettings::class,
        Tools\ClearAnswerCache::class,
        Tools\ReindexKnowledgeSource::class,
        Tools\SetConnectorToolEnabled::class,
    ];

    /** @return Collection<int, Tool> */
    public static function all(): Collection
    {
        return collect(self::TOOLS)->map(fn ($class) => new $class());
    }

    public static function forUser(User $user): Collection
    {
        return self::all()->filter(fn (Tool $tool) => $tool->offeredTo($user))->values();
    }

    public static function find(User $user, string $name): ?Tool
    {
        return self::forUser($user)->first(fn (Tool $tool) => $tool->name === $name);
    }
}
