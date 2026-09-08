<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotProfile extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'system_id',
        'name',
        'system_prompt',
        'provider_type',
        'base_url',
        'api_key',
        'model_name',
        'temperature',
        'max_tokens',
        'widget_title',
        'widget_greeting',
        'widget_primary_color',
        'widget_position',
        'launcher_icon_url',
        'launcher_shape',
        'bot_avatar_url',
        'avatar_shape',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'bot_id');
    }
}
