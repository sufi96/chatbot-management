<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An answer a bot may give again. The engine writes and reads these
 * (api-engine/answer_cache.py); the portal only counts them and empties a
 * bot's when its behaviour changes, since a new prompt or new settings can make
 * every one of them wrong.
 */
class AnswerCache extends Model
{
    protected $table = 'answer_cache';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'created_at' => 'datetime',
            'last_hit_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(BotProfile::class, 'bot_id');
    }

    /** Empties a bot's cache. Returns how many answers were dropped. */
    public static function forget(string $botId): int
    {
        return static::where('bot_id', $botId)->delete();
    }
}
