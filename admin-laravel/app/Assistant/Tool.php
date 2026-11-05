<?php

namespace App\Assistant;

use App\Models\User;

/**
 * One thing the console assistant can do.
 *
 * Each tool checks the person's rights on the actual target itself, with
 * canManageSystem at the same role the console page for that action asks for.
 * Results are short plain lines: a small model reads them and rewrites them.
 * Never put a secret in a result. See docs/admin-assistant.md.
 */
abstract class Tool
{
    /** Model-safe: letters, digits and underscores. */
    public string $name;

    public string $description;

    /** A change: shown to the person for Confirm before it runs, and recorded. */
    public bool $writes = false;

    /** The least role, in some workspace, for the tool to be offered at all. */
    public string $minRole = 'viewer';

    public bool $superOnly = false;

    /** JSON schema of the arguments. */
    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    /** @throws Refusal */
    abstract public function run(User $user, array $args): string;

    public function offeredTo(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if ($this->superOnly) {
            return false;
        }

        return $user->systems->contains(fn ($system) => $user->canManageSystem($system->id, $this->minRole));
    }

    public function describe(): array
    {
        return ['name' => $this->name, 'description' => $this->description,
            'parameters' => $this->parameters(), 'writes' => $this->writes];
    }

    /** An argument that should be true or false, or null when not given. */
    protected static function bool(array $args, string $key): ?bool
    {
        if (!array_key_exists($key, $args) || $args[$key] === null) {
            return null;
        }
        $value = is_bool($args[$key]) ? $args[$key]
            : filter_var($args[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new Refusal("{$key} must be true or false.");
        }

        return $value;
    }

    protected static function days(array $args, int $default = 7): int
    {
        return max(1, min(90, (int) ($args['days'] ?? $default)));
    }

    /** A schema property: a bot named by id or name. */
    protected static function botParam(): array
    {
        return ['type' => 'string', 'description' => 'The bot, by id or by name.'];
    }

    protected static function workspaceParam(): array
    {
        return ['type' => 'string', 'description' => 'A workspace, by id or by name. Leave out for all of mine.'];
    }
}
