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

    /**
     * Fused retrieval scores top out near 0.016, so a zero floor admits every
     * weak match. The column default stays 0 for older rows; new bots start here.
     */
    protected $attributes = [
        'retrieval_min_score' => 0.01,
    ];

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
        'launcher_size',
        'close_icon_url',
        'close_shape',
        'close_size',
        'bot_avatar_url',
        'avatar_shape',
        'is_active',
        'retrieval_enabled',
        'retrieval_mode',
        'retrieval_top_k',
        'retrieval_candidates',
        'retrieval_min_score',
        'retrieval_fallback',
        'top_p',
        'top_k_sampling',
        'presence_penalty',
        'frequency_penalty',
        'thinking_level',
    ];

    protected function casts(): array
    {
        return [
            'temperature' => 'float',
            'max_tokens' => 'integer',
            'launcher_size' => 'integer',
            'close_size' => 'integer',
            'is_active' => 'boolean',
            'retrieval_enabled' => 'boolean',
            'retrieval_top_k' => 'integer',
            'retrieval_candidates' => 'integer',
            'retrieval_min_score' => 'float',
            'top_p' => 'float',
            'top_k_sampling' => 'integer',
            'presence_penalty' => 'float',
            'frequency_penalty' => 'float',
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

    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(KbCollection::class, 'bot_kb_collection', 'bot_id', 'collection_id');
    }
}
