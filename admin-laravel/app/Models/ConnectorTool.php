<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One operation on a connector, which an admin may switch on for bots. */
class ConnectorTool extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'connector_id', 'name', 'description', 'method', 'path', 'parameters', 'writes', 'enabled'];

    protected $casts = ['parameters' => 'array', 'writes' => 'boolean', 'enabled' => 'boolean'];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'connector_id');
    }
}
