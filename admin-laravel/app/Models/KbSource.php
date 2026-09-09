<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbSource extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'collection_id', 'type', 'title', 'body',
        'file_path', 'file_mime', 'file_size',
        'status', 'error_message', 'chunk_count', 'indexed_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_count' => 'integer',
            'file_size' => 'integer',
            'indexed_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(KbCollection::class, 'collection_id');
    }
}
