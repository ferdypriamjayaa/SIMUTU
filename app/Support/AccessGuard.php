<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Prevents privilege escalation in access management: nobody hands out a
 * permission they do not hold themselves, and only holders of the system
 * role (Admin) may assign it or change accounts that hold it.
 */
final class AccessGuard
{
    public static function canGrant(User $actor, string $permission): bool
    {
        return $actor->hasSystemRole() || $actor->hasPermission($permission);
    }

    /**
     * Permissions in the list the actor may not grant.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function ungrantable(User $actor, array $permissions): array
    {
        return array_values(array_filter($permissions, fn (string $p) => ! self::canGrant($actor, $p)));
    }

    public static function canAssignRole(User $actor, Role $role): bool
    {
        if ($role->is_system) {
            return $actor->hasSystemRole();
        }

        return self::ungrantable($actor, $role->permissionKeys()) === [];
    }

    /**
     * Accounts holding the system role are managed by Admin only.
     */
    public static function canManageUser(User $actor, User $target): bool
    {
        return ! $target->hasSystemRole() || $actor->hasSystemRole();
    }
}
