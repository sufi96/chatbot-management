<?php

namespace App\Models;

use App\Support\SkillFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A skill: when to use it, which tools it needs, and the instructions. See
 * the migration that added it, and SkillFile for the file format.
 */
class Skill extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['id', 'system_id', 'name', 'description', 'body', 'tools', 'origin', 'mode'];

    public const MODES = ['progressive' => 'When a request matches', 'always' => 'Always on'];

    protected $casts = ['tools' => 'array'];

    public const ORIGINS = ['upload', 'typed', 'generated', 'builtin'];

    public function bots(): BelongsToMany
    {
        return $this->belongsToMany(BotProfile::class, 'bot_skills', 'skill_id', 'bot_id');
    }

    public function isBuiltIn(): bool
    {
        return $this->system_id === null;
    }

    public function toFile(): string
    {
        return SkillFile::render(['name' => $this->name, 'description' => $this->description,
            'tools' => $this->tools ?? [], 'body' => $this->body]);
    }
}
