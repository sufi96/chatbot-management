<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbConnection extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'system_id', 'name', 'driver', 'host', 'port', 'database',
        'username', 'password', 'options', 'status', 'error_message',
        'last_introspected_at', 'is_enabled',
    ];

    /**
     * The encrypted cast puts the password beyond a database dump. It does
     * not put it beyond the application key, which is why the connection
     * form asks for a read-only account.
     */
    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'options' => 'array',
            'last_introspected_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * The tables a bot is actually allowed to read.
     *
     * Both switches have to agree. A ticked table inside a switched-off
     * connection is not readable, which is the whole point of the master
     * switch, and stage two must ask this rather than the table flag alone.
     */
    public function readableTables()
    {
        return $this->tables()
            ->where('is_enabled', true)
            ->where('is_present', true);
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DbTable::class, 'connection_id');
    }

    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(
            BotProfile::class, 'bot_db_connection', 'connection_id', 'bot_id');
    }
}
