<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AiProvider;
use App\Models\User;

/** Choosing a bot's endpoint, shared by the new-bot form and the Behaviour tab. */
trait PicksProviders
{
    /**
     * The endpoints this user may choose from: the bot's own workspace first,
     * then other workspaces by name, then the platform's.
     *
     * The bot's current provider stays even when this user could not pick it
     * (a super admin set it), flagged so the form names it without its URL or key.
     */
    protected function providersFor(User $user, ?string $systemId, ?string $currentId = null)
    {
        $providers = AiProvider::usableBy($user)->with('system')->withCount('bots')->get();

        if ($currentId && !$providers->contains('id', $currentId)) {
            $current = AiProvider::with('system')->withCount('bots')->find($currentId);
            if ($current) {
                $current->setAttribute('locked', true);
                $providers->push($current);
            }
        }

        return $providers->sortBy(fn (AiProvider $p) => [
            $p->system_id === $systemId ? 0 : ($p->system_id ? 1 : 2),
            $p->ownerName(),
            $p->name,
        ])->values();
    }

    /**
     * A bot may point only at an endpoint this user may use, or keep the one
     * it already has. Checking here is what stops a crafted form id from
     * borrowing a key the user was never shown.
     */
    protected function providerRule(User $user, ?string $currentId = null): array
    {
        return ['required', 'string', function (string $attribute, $value, $fail) use ($user, $currentId) {
            if ($value === $currentId) {
                return;
            }
            if (!AiProvider::usableBy($user)->whereKey($value)->exists()) {
                $fail('Pick a provider from the list.');
            }
        }];
    }
}
