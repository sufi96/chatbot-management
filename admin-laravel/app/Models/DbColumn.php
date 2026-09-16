<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbColumn extends Model
{
    protected $fillable = [
        'table_id', 'column_name', 'data_type', 'is_nullable',
        'is_primary_key', 'foreign_key_target', 'description', 'ordinal',
        'is_present',
    ];

    protected function casts(): array
    {
        return [
            'is_nullable' => 'boolean',
            'is_primary_key' => 'boolean',
            'is_present' => 'boolean',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DbTable::class, 'table_id');
    }
}
