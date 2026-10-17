<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\Auditable;
use App\Traits\HasUuidAndSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasUuidAndSync, Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'email',
        'phone',
        'role',
        'password',
        'cached_password_hash',
        'must_change_password',
        'is_active',
        'photo_path',
        'created_by',
        'sync_status',
        'last_modified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'cached_password_hash',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_modified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'user_id');
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        $currentRoleValue = $this->role instanceof UserRole ? $this->role->value : $this->role;

        foreach ($roles as $role) {
            $roleValue = $role instanceof UserRole ? $role->value : $role;
            if ($currentRoleValue === $roleValue) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::ADMINISTRATOR);
    }

    public function isClinician(): bool
    {
        return $this->hasRole(UserRole::CLINICIAN);
    }

    public function isLabTech(): bool
    {
        return $this->hasRole(UserRole::LAB_TECHNICIAN);
    }

    public function isDataClerk(): bool
    {
        return $this->hasRole(UserRole::DATA_CLERK);
    }

    public function canManageUser(User $targetUser): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isClinician()) {
            return $targetUser->hasRole(UserRole::LAB_TECHNICIAN, UserRole::DATA_CLERK);
        }

        return false;
    }

    public function canAssignRole(UserRole|string $role): bool
    {
        $targetRole = is_string($role) ? UserRole::tryFrom($role) : $role;
        if (!$targetRole) {
            return false;
        }

        if ($this->role instanceof UserRole) {
            return in_array($targetRole, $this->role->assignableRoles(), true);
        }

        return false;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
