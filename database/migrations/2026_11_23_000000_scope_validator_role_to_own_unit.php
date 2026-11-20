<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default Validator role was created with data.view_all_units, so its
 * holders saw every unit. Validators review their own unit's data, like PIC
 * Unit: only that permission row of that role is removed. Accounts, units and
 * per-user overrides are untouched (an Admin can still grant the permission
 * to a specific user on purpose).
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleId = DB::table('roles')->where('slug', 'validator')->value('id');

        if ($roleId !== null) {
            DB::table('role_permissions')->where('role_id', $roleId)->where('permission', 'data.view_all_units')->delete();
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('slug', 'validator')->value('id');

        if ($roleId !== null) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId, 'permission' => 'data.view_all_units', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
};
