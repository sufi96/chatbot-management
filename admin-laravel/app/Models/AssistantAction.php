<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A change someone asked the console assistant to make, made or refused. */
class AssistantAction extends Model
{
    protected $fillable = ['user_id', 'tool', 'arguments', 'ok', 'result'];

    protected $casts = ['arguments' => 'array', 'ok' => 'boolean'];
}
