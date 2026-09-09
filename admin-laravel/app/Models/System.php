<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class System extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'description',
        'allowed_origins',
    ];

    /**
     * Users assigned to this system.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'system_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Bot profiles belonging to this system.
     */
    public function kbCollections(): HasMany
    {
        return $this->hasMany(KbCollection::class, 'system_id');
    }

    public function botProfiles(): HasMany
    {
        return $this->hasMany(BotProfile::class, 'system_id');
    }
}
