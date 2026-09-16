<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbCollection extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'description'];

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KbSource::class, 'collection_id');
    }

    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(BotProfile::class, 'bot_kb_collection', 'collection_id', 'bot_id');
    }
}
