<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A file a visitor attached ("in") or a bot handed back ("out"). The engine
 * writes these and keeps the bytes; the portal lists them in transcripts and
 * downloads them through the engine. The visitor's link token and the text
 * read from the file never leave the portal.
 */
class ChatFile extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = ['id', 'conversation_id', 'message_id', 'bot_id', 'direction', 'name', 'mime', 'ext',
        'size', 'storage_key', 'sha256', 'scan_status', 'text', 'read_error', 'flag', 'ip_hash', 'token',
        'created_at', 'expires_at'];

    protected $hidden = ['token', 'text', 'storage_key', 'sha256', 'ip_hash'];

    protected $casts = ['size' => 'integer', 'created_at' => 'datetime', 'expires_at' => 'datetime'];

    public function conversation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
