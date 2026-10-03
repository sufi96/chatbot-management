<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workspace's own Tavily or Brave key, which its bots may search with
 * instead of the platform's. See the migration that added it.
 *
 * The key goes in and never comes back out: it is hidden from every array and
 * JSON form, and a test of a saved key looks it up on the server.
 */
class WebSearchKey extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'provider', 'api_key'];

    protected $hidden = ['api_key'];

    /** The paid providers a workspace can bring a key for. DuckDuckGo needs none. */
    public const PROVIDERS = [
        'tavily' => 'Tavily',
        'brave' => 'Brave',
    ];

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function bots(): HasMany
    {
        return $this->hasMany(BotProfile::class, 'web_search_key_id');
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    /** What the picker and the modal read: never the key, only its last four. */
    public function forPicker(): array
    {
        $key = (string) $this->api_key;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider,
            'provider_label' => $this->providerLabel(),
            'hint' => strlen($key) > 8 ? '…' . substr($key, -4) : '',
            'bots' => $this->bots()->count(),
        ];
    }
}
