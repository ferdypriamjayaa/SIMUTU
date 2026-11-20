<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dynamic roles (Jabatan) with permissions, and per-user permission overrides.
 *
 * users.role keeps its values: each becomes the slug of a role row, so
 * existing accounts, NIPs, passwords and units are untouched. Only rows are
 * added; nothing is reset or truncated.
 */
return new class extends Migration
{
    /** Permissions of the default roles at the time of this migration. */
    private const DEFAULTS = [
        'admin' => ['name' => 'Admin', 'system' => true, 'description' => 'Administrator sistem. Selalu memiliki semua izin.', 'permissions' => 'all'],
        'pic_mutu' => ['name' => 'PIC Mutu', 'system' => false, 'description' => 'Tim mutu: memantau data semua unit, laporan, dan ekspor.', 'permissions' => [
            'dashboard.view', 'observations.view', 'reports.view', 'reports.export_excel', 'reports.export_pdf', 'indicators.view', 'data.view_all_units',
        ]],
        'pic_unit' => ['name' => 'PIC Unit', 'system' => false, 'description' => 'Petugas unit: menginput dan mengelola observasi unitnya sendiri.', 'permissions' => [
            'dashboard.view', 'observations.view', 'observations.create', 'observations.edit', 'observations.delete', 'reports.view',
        ]],
        'validator' => ['name' => 'Validator', 'system' => false, 'description' => 'Hanya melihat laporan (baca saja).', 'permissions' => [
            'dashboard.view', 'observations.view', 'reports.view', 'data.view_all_units',
        ]],
    ];

    /** Every permission identifier when this migration was written. */
    private const ALL = [
        'dashboard.view',
        'observations.view', 'observations.create', 'observations.edit', 'observations.delete',
        'reports.view', 'reports.export_excel', 'reports.export_pdf',
        'data.view_all_units',
        'indicators.view', 'indicators.create', 'indicators.edit', 'indicators.delete',
        'units.view', 'units.create', 'units.edit', 'units.delete',
        'users.view', 'users.create', 'users.edit', 'users.delete',
        'integrations.view', 'integrations.manage', 'integrations.import',
        'roles.view', 'roles.manage', 'permissions.manage',
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission', 100);
            $table->timestamps();

            $table->unique(['role_id', 'permission']);
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 100);
            $table->boolean('is_granted');
            $table->timestamps();

            $table->unique(['user_id', 'permission']);
        });

        $now = now();

        foreach (self::DEFAULTS as $slug => $role) {
            $id = DB::table('roles')->insertGetId([
                'slug' => $slug,
                'name' => $role['name'],
                'description' => $role['description'],
                'is_active' => true,
                'is_system' => $role['system'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissions = $role['permissions'] === 'all' ? self::ALL : $role['permissions'];
            DB::table('role_permissions')->insert(array_map(fn ($p) => [
                'role_id' => $id, 'permission' => $p, 'created_at' => $now, 'updated_at' => $now,
            ], $permissions));
        }

        // Any other value already in users.role becomes a role without permissions,
        // so the foreign key below never rejects an existing account.
        $unknown = DB::table('users')->whereNotIn('role', array_keys(self::DEFAULTS))->distinct()->pluck('role');

        foreach ($unknown as $slug) {
            DB::table('roles')->insert([
                'slug' => $slug,
                'name' => Str::headline($slug),
                'description' => null,
                'is_active' => true,
                'is_system' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // A role cannot be removed while accounts still hold it.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role')->references('slug')->on('roles')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role']);
        });

        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
