<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connector tool call a bot made, or a change waiting on the visitor's
 * Confirm. The engine writes these; the portal only reads them.
 */
class ChatToolCall extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'conversation_id', 'message_id', 'connector_tool_id', 'tool_name',
        'arguments', 'status', 'flag', 'result_excerpt', 'duration_ms'];

    protected $casts = ['arguments' => 'array', 'duration_ms' => 'integer'];
}
