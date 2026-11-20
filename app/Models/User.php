<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\Permissions;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_PIC_MUTU = 'pic_mutu';

    public const ROLE_PIC_UNIT = 'pic_unit';

    public const ROLE_VALIDATOR = 'validator';

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

    /**
     * The position (Jabatan) this account holds, by its slug.
     */
    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role', 'slug');
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /**
     * Effective permissions with their source, per App\Support\Permissions::resolve().
     *
     * @return array<string, array{role: bool, override: ?bool, effective: bool, system: ?string}>
     */
    public function permissionMatrix(): array
    {
        $role = $this->assignedRole;

        return Permissions::resolve(
            $role?->permissionKeys() ?? [],
            $this->permissionOverrides->pluck('is_granted', 'permission')->all(),
            (bool) $role?->is_system,
            (bool) $role?->is_active,
            $this->is_active !== false,
        );
    }

    /**
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        return array_keys(array_filter($this->permissionMatrix(), fn (array $p) => $p['effective']));
    }

    public function hasPermission(string $permission): bool
    {
        return $this->permissionMatrix()[$permission]['effective'] ?? false;
    }

    /**
     * Indicator category codes the user may use for an area action
     * (view, create, edit, export), e.g. ['INM'] with inm.view only.
     *
     * @return list<string>
     */
    public function categoryCodes(string $action): array
    {
        $codes = [];

        foreach (Permissions::AREAS as $area => $areaCodes) {
            if ($this->hasPermission("{$area}.{$action}")) {
                array_push($codes, ...$areaCodes);
            }
        }

        return $codes;
    }

    public function canAccessCategory(?string $categoryCode, string $action): bool
    {
        $area = Permissions::areaOf($categoryCode);

        return $area !== null && $this->hasPermission("{$area}.{$action}");
    }

    /**
     * Whether the user may use the action on at least one area.
     */
    public function canAccessAnyArea(string $action): bool
    {
        return $this->categoryCodes($action) !== [];
    }

    /**
     * Forget loaded role and overrides, after they were changed.
     */
    public function forgetPermissions(): static
    {
        return $this->unsetRelation('assignedRole')->unsetRelation('permissionOverrides');
    }

    /**
     * Holder of the system role (Admin), which always has every permission.
     */
    public function hasSystemRole(): bool
    {
        return (bool) $this->assignedRole?->is_system;
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
     * Whether the account sees data of every unit; otherwise only its own unit.
     */
    public function canViewAllUnits(): bool
    {
        return $this->hasPermission(Permissions::VIEW_ALL_UNITS);
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
        return $this->assignedRole?->name ?? (string) $this->role;
    }
}
