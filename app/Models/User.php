<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_PIC_MUTU = 'pic_mutu';

    public const ROLE_PIC_UNIT = 'pic_unit';

    public const ROLES = [
        self::ROLE_ADMIN => 'Admin',
        self::ROLE_PIC_MUTU => 'PIC Mutu',
        self::ROLE_PIC_UNIT => 'PIC Unit',
    ];

    protected $fillable = [
        'nip',
        'name',
        'email',
        'password',
        'role',
        'unit_id',
        'is_active',
        'hris_employee_id',
        'hris_company_code',
        'hris_linked_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'hris_employee_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'hris_linked_at' => 'datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isPicMutu(): bool
    {
        return $this->role === self::ROLE_PIC_MUTU;
    }

    public function isPicUnit(): bool
    {
        return $this->role === self::ROLE_PIC_UNIT;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Admin and PIC Mutu see data from every unit.
     */
    public function canViewAllUnits(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN, self::ROLE_PIC_MUTU);
    }

    /**
     * The account's unit, only when it is assigned and active.
     */
    public function activeUnit(): ?Unit
    {
        return $this->unit?->is_active ? $this->unit : null;
    }

    public function isLinkedToHris(): bool
    {
        return $this->hris_employee_id !== null;
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}
