<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IMP Unit gets its own permissions (imp_unit.*) next to IMP RS (imp.*), and
 * deleting an observation its own action (*.delete), split from *.edit.
 *
 * Only permission rows are added, so access stays exactly as before: a role or
 * user holding imp.X now also holds imp_unit.X (imp.* covered both categories
 * until now), and a holder of *.edit also holds *.delete. User restrictions
 * are copied the same way. Nothing is removed on the way up.
 */
return new class extends Migration
{
    /** existing => added */
    private const COPIES = [
        'imp.view' => ['imp_unit.view'],
        'imp.create' => ['imp_unit.create'],
        'imp.edit' => ['imp_unit.edit', 'imp.delete', 'imp_unit.delete'],
        'imp.export' => ['imp_unit.export'],
        'inm.edit' => ['inm.delete'],
    ];

    private const ADDED = [
        'imp_unit.view', 'imp_unit.create', 'imp_unit.edit', 'imp_unit.delete', 'imp_unit.export',
        'inm.delete', 'imp.delete',
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            foreach (self::COPIES as $from => $targets) {
                foreach (DB::table('role_permissions')->where('permission', $from)->get() as $row) {
                    foreach ($targets as $target) {
                        DB::table('role_permissions')->insertOrIgnore([
                            'role_id' => $row->role_id, 'permission' => $target, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }

                foreach (DB::table('user_permissions')->where('permission', $from)->get() as $row) {
                    foreach ($targets as $target) {
                        DB::table('user_permissions')->insertOrIgnore([
                            'user_id' => $row->user_id, 'permission' => $target, 'is_granted' => $row->is_granted,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', self::ADDED)->delete();
        DB::table('user_permissions')->whereIn('permission', self::ADDED)->delete();
    }
};
