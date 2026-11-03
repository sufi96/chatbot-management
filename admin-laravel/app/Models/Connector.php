<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outside API a workspace's bots may call. See the migration that added it.
 *
 * The secret goes in and never comes back out, as with WebSearchKey: hidden
 * from every array and JSON form, and a test of a saved connector looks it up
 * on the server.
 */
class Connector extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'type', 'base_url', 'auth_type', 'auth_header', 'auth_secret'];

    protected $hidden = ['auth_secret'];

    public const TYPES = ['rest' => 'REST / OpenAPI', 'mcp' => 'MCP server'];

    public const AUTH_TYPES = ['none', 'bearer', 'header', 'basic'];

    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class, 'system_id');
    }

    public function tools(): HasMany
    {
        return $this->hasMany(ConnectorTool::class, 'connector_id');
    }

    /** What the page reads: never the secret, only its last four. */
    public function forPage(): array
    {
        $secret = (string) $this->auth_secret;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'base_url' => $this->base_url,
            'auth_type' => $this->auth_type,
            'auth_header' => $this->auth_header,
            'hint' => strlen($secret) > 8 ? '…' . substr($secret, -4) : '',
            'tools' => $this->tools()->count(),
        ];
    }
}
