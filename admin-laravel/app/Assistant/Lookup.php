<?php

namespace App\Assistant;

use App\Models\BotProfile;
use App\Models\System;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Finding what a person named, among what they may see.
 *
 * Small models name things ("the HR bot") more reliably than they copy ids,
 * so a bot or workspace may be named by id or by name. The search runs only
 * over what the person may reach: another workspace's bot is not "refused",
 * it is simply not there.
 */
class Lookup
{
    /** Workspaces the person holds at least this role in. */
    public static function workspaces(User $user, string $minRole = 'viewer'): Collection
    {
        $all = $user->isSuperAdmin() ? System::orderBy('name')->get() : $user->systems->sortBy('name')->values();

        return $all->filter(fn ($system) => $user->canManageSystem($system->id, $minRole))->values();
    }

    public static function workspace(User $user, ?string $ref, string $minRole = 'viewer'): System
    {
        $ref = trim((string) $ref);
        $mine = self::workspaces($user, $minRole);
        $found = $mine->filter(fn ($s) => $s->id === $ref || strcasecmp($s->name, $ref) === 0);
        if ($found->isEmpty() && $ref !== '') {
            $found = $mine->filter(fn ($s) => stripos($s->name, $ref) !== false);
        }

        return self::one($found, "workspace \"{$ref}\"", fn ($s) => "{$s->name} ({$s->id})");
    }

    /** Bots the person may see: their workspaces' bots, and the console assistant for a super admin. */
    public static function bots(User $user, ?string $workspace = null, string $minRole = 'viewer'): Collection
    {
        $scoped = $workspace !== null && trim($workspace) !== '';
        $ids = $scoped ? [self::workspace($user, $workspace, $minRole)->id]
            : self::workspaces($user, $minRole)->pluck('id')->all();

        return BotProfile::query()
            ->where(function ($q) use ($ids, $user, $scoped) {
                $q->whereIn('system_id', $ids);
                if ($user->isSuperAdmin() && !$scoped) {
                    $q->orWhere('is_platform', true);
                }
            })
            ->orderBy('name')->get();
    }

    public static function bot(User $user, ?string $ref, string $minRole = 'viewer'): BotProfile
    {
        $ref = trim((string) $ref);
        $matches = fn (Collection $bots) => $bots->filter(fn ($b) => $b->id === $ref || strcasecmp($b->name, $ref) === 0);
        $found = $matches(self::bots($user, null, $minRole));
        if ($found->isEmpty() && $ref !== '') {
            $found = self::bots($user, null, $minRole)->filter(fn ($b) => stripos($b->name, $ref) !== false);
        }
        if ($found->isEmpty() && $matches(self::bots($user))->isNotEmpty()) {
            throw new Refusal("Your role does not let you do this to bot \"{$ref}\".");
        }

        return self::one($found, "bot \"{$ref}\"", fn ($b) => "{$b->name} ({$b->id})");
    }

    private static function one(Collection $found, string $what, callable $label)
    {
        if ($found->count() === 1) {
            return $found->first();
        }
        if ($found->isEmpty()) {
            throw new Refusal("No {$what} that you can reach.");
        }

        throw new Refusal("More than one match for {$what}: " . $found->map($label)->implode('; ') . '. Say which one.');
    }
}
