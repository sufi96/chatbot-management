<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'conversation_id',
        'sender',
        'content',
        'reasoning',
        'tokens_used',
        // Prompt and completion, the two halves of tokens_used. Null when the
        // endpoint reported no usage or the answer predates them.
        'tokens_in',
        'tokens_out',
        // The statement that answered, when live data did. Null on every
        // message the database did not answer.
        'db_sql',
        'db_row_count',
        // Which model did each job, as JSON. Null on messages written before
        // the column existed, and on visitor messages.
        'model_trace',
        // What the intent step decided, on the visitor's own row.
        'intent',
        'intent_query',
        // What the guard named, when it refused a message or flagged an answer.
        'guard_flag',
        // On an answer, for the analytics page: which source answered, the
        // citations as JSON, and the visitor's wait. Null before tracking began.
        'source_kind',
        'citations',
        'first_token_ms',
        'response_ms',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
