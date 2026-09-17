<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BotProfile extends Model
{
    // Deleting from a workspace only marks the bot; see the migration that
    // added deleted_at. Only a super admin restores or purges one.
    use HasFactory, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * An unrelated top hit scores about 0.0164 from one retrieval branch, and
     * only reaches about 0.033 when both branches agree. A floor below 0.0164
     * therefore admits passages about the wrong subject on every question, and
     * leaves the knowledge base looking occupied so the web fallback never
     * runs. 0.02 sits clearly between the two levels.
     */
    protected $attributes = [
        'retrieval_min_score' => 0.02,
        // A reranker's score is a relevance judgement from 0 to 1, and an
        // unrelated passage sits far below a tenth. Read only when one is set.
        'rerank_min_score' => 0.1,
        // Where nomic-embed-text separated passages that answered from ones
        // that did not. See the migration that added it.
        'retrieval_min_similarity' => 0.65,
    ];

    protected $fillable = [
        'id',
        'system_id',
        'name',
        'system_prompt',
        'provider_id',
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
        'rerank_min_score',
        'retrieval_min_similarity',
        'retrieval_fallback',
        'web_search_enabled',
        'web_search_max_results',
        'web_search_country',
        'db_query_enabled',
        'db_max_rows',
        'db_query_timeout',
        'source_order',
        'intent_enabled',
        'guard_enabled',
        'guard_refusal',
        'guard_topics',
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
            'db_query_enabled' => 'boolean',
            'intent_enabled' => 'boolean',
            'guard_enabled' => 'boolean',
            'db_max_rows' => 'integer',
            'db_query_timeout' => 'integer',
            'retrieval_top_k' => 'integer',
            'retrieval_candidates' => 'integer',
            'retrieval_min_score' => 'float',
            'rerank_min_score' => 'float',
            'retrieval_min_similarity' => 'float',
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

    /**
     * The endpoint this bot talks to. A link rather than a copy, so an
     * operator who moves machines edits one provider instead of every bot.
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatConversation::class, 'bot_id');
    }

    public function collections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(KbCollection::class, 'bot_kb_collection', 'bot_id', 'collection_id');
    }

    public function dbConnections(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(DbConnection::class, 'bot_db_connection', 'bot_id', 'connection_id');
    }
}
