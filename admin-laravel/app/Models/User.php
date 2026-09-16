<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'global_role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Systems assigned to this user with system-level roles.
     */
    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(System::class, 'system_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Check if user is a global Super Admin.
     */
    public function isSuperAdmin(): bool
    {
        return $this->global_role === 'super_admin';
    }

    /**
     * Get user's role in a given system.
     */
    public function roleInSystem(string $systemId): ?string
    {
        if ($this->isSuperAdmin()) {
            return 'super_admin';
        }

        $system = $this->systems->firstWhere('id', $systemId);
        return $system ? $system->pivot->role : null;
    }

    /**
     * Check if user has at least the required role in a given system.
     * Roles hierarchy: system_admin > editor > viewer
     */
    public function canManageSystem(string $systemId, string $minRole = 'viewer'): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $userRole = $this->roleInSystem($systemId);
        if (!$userRole) {
            return false;
        }

        $hierarchy = [
            'viewer' => 1,
            'editor' => 2,
            'system_admin' => 3,
            'super_admin' => 4,
        ];

        return ($hierarchy[$userRole] ?? 0) >= ($hierarchy[$minRole] ?? 0);
    }
}
