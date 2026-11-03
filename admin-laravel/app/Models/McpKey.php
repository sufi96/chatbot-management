<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A workspace's key for other AI tools to use its bots over MCP. See the migration. */
class McpKey extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'token_hash', 'hint'];

    protected $hidden = ['token_hash'];

    protected $casts = ['last_used_at' => 'datetime'];
}
