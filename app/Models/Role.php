<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job position (Jabatan) and the permissions it grants. Users reference it
 * by slug (users.role), which never changes after creation.
 */
class Role extends Model
{
    public const ADMIN = 'admin';

    protected $fillable = ['slug', 'name', 'description', 'is_active', 'is_system'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'slug');
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('permission')->values()->all();
    }

    /**
     * @param  list<string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        $this->permissions()->whereNotIn('permission', $permissions)->delete();

        $existing = $this->permissions()->pluck('permission')->all();

        foreach (array_diff($permissions, $existing) as $permission) {
            $this->permissions()->create(['permission' => $permission]);
        }

        $this->unsetRelation('permissions');
    }
}
