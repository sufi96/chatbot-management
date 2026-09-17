<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * An OpenAI-compatible endpoint a bot can be pointed at.
 *
 * Bots link to one rather than copying it, so changing the laptop's address
 * here moves every bot standing on it.
 *
 * A row with no workspace is a platform provider: Admin Settings links model
 * jobs to it, and only a super admin can point a bot at it.
 */
class AiProvider extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'base_url', 'api_key'];

    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('system_id');
    }

    /**
     * The endpoints a user may point a bot at: every one for a super admin,
     * otherwise those of each workspace they edit. Viewing a workspace does
     * not lend its keys.
     */
    public function scopeUsableBy(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        $editable = $user->systems
            ->filter(fn (System $system) => $user->canManageSystem($system->id, 'editor'))
            ->pluck('id');

        return $query->whereIn('system_id', $editable);
    }

    /**
     * Whether a user may see where this endpoint is. A bot's page names its
     * provider either way; the URL stays with those who belong to its owner.
     */
    public function isVisibleTo(User $user): bool
    {
        return $user->isSuperAdmin()
            || ($this->system_id !== null && $user->canManageSystem($this->system_id));
    }

    /**
     * Refuses an entry the picker could not tell apart from one its owner
     * already has: the same name, or the same endpoint with the same key.
     * Another workspace may reuse either, since the picker names the owner.
     */
    public static function refuseLookalike(?string $systemId, array $fields, ?string $ignoreId = null): void
    {
        $siblings = static::query()
            ->when($systemId, fn ($q) => $q->where('system_id', $systemId), fn ($q) => $q->whereNull('system_id'))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->get();

        $owner = $systemId ? 'This workspace' : 'The platform';
        $name = mb_strtolower(trim($fields['name']));
        $url = rtrim(trim($fields['base_url']), '/');

        if ($twin = $siblings->first(fn ($p) => mb_strtolower(trim($p->name)) === $name)) {
            throw ValidationException::withMessages([
                'name' => "{$owner} already has a provider named \"{$twin->name}\". Give this one a name that says how it differs.",
            ]);
        }

        if ($twin = $siblings->first(fn ($p) => rtrim(trim($p->base_url), '/') === $url && $p->api_key === $fields['api_key'])) {
            throw ValidationException::withMessages([
                'base_url' => "\"{$twin->name}\" already points here with the same key. Use that one instead of saving a copy.",
            ]);
        }
    }

    /** Whose endpoint this is, so two with the same name can be told apart. */
    public function ownerName(): string
    {
        return $this->system?->name ?? 'Platform';
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function bots(): HasMany
    {
        return $this->hasMany(BotProfile::class, 'provider_id');
    }

    /**
     * What the picker shows: the name an operator gave it, and the host that
     * says which machine it actually is.
     */
    public function label(): string
    {
        return $this->name . ' — ' . $this->base_url;
    }
}
