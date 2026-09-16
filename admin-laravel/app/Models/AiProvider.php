<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OpenAI-compatible endpoint a bot can be pointed at.
 *
 * Bots link to one rather than copying it, so changing the laptop's address
 * here moves every bot standing on it.
 */
class AiProvider extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'base_url', 'api_key'];

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
