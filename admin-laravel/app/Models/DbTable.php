<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbTable extends Model
{
    protected $fillable = [
        'connection_id', 'schema_name', 'table_name', 'description',
        'is_enabled', 'is_present',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'is_present' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(DbConnection::class, 'connection_id');
    }

    public function columns(): HasMany
    {
        return $this->hasMany(DbColumn::class, 'table_id')->orderBy('ordinal');
    }

    /** How the table is named in SQL and in the prompt. */
    public function qualifiedName(): string
    {
        return $this->schema_name
            ? "{$this->schema_name}.{$this->table_name}"
            : $this->table_name;
    }
}
