<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Separate INM and IMP access, and one permission set per integration menu.
 *
 * Old identifiers are converted, never dropped without a replacement: a role
 * or user that held one now holds its equivalents for both INM and IMP (or
 * both integration menus), so nobody gains or loses access by this migration.
 * Only rows of role_permissions and user_permissions change.
 */
return new class extends Migration
{
    /** old => [replacements]; `keep` stays as well. */
    private const MAP = [
        'observations.view' => ['inm.view', 'imp.view'],
        'observations.create' => ['keep', 'inm.create', 'imp.create'],
        'observations.edit' => ['inm.edit', 'imp.edit'],
        'observations.delete' => ['inm.edit', 'imp.edit'],
        'reports.view' => ['keep', 'inm.view', 'imp.view'],
        'reports.export_excel' => ['inm.export', 'imp.export'],
        'reports.export_pdf' => ['inm.export', 'imp.export'],
        'indicators.edit' => ['keep', 'indicator_mappings.manage'],
        'integrations.view' => ['hris_mappings.view', 'jotform.view'],
        'integrations.manage' => ['hris_mappings.manage', 'jotform.manage'],
        'integrations.import' => ['hris_mappings.sync', 'jotform.import'],
    ];

    /** For rollback: new => old identifiers it came from. */
    private const REVERSE = [
        'inm.view' => ['observations.view'], 'imp.view' => ['observations.view'],
        'inm.create' => [], 'imp.create' => [],
        'inm.edit' => ['observations.edit', 'observations.delete'], 'imp.edit' => ['observations.edit', 'observations.delete'],
        'inm.export' => ['reports.export_excel', 'reports.export_pdf'], 'imp.export' => ['reports.export_excel', 'reports.export_pdf'],
        'indicator_mappings.manage' => [],
        'hris_mappings.view' => ['integrations.view'], 'jotform.view' => ['integrations.view'],
        'hris_mappings.manage' => ['integrations.manage'], 'jotform.manage' => ['integrations.manage'],
        'hris_mappings.sync' => ['integrations.import'], 'jotform.import' => ['integrations.import'],
    ];

    public function up(): void
    {
        $this->convert(fn (string $p) => self::MAP[$p] ?? ['keep']);
    }

    public function down(): void
    {
        $this->convert(fn (string $p) => array_key_exists($p, self::REVERSE) ? self::REVERSE[$p] : ['keep']);
    }

    /**
     * Rewrites both tables with $map(permission) => replacements ('keep' keeps it).
     * Roles: union of grants. Users: a restriction wins over a grant on the
     * same resulting permission, so an override never becomes more permissive.
     */
    private function convert(callable $map): void
    {
        DB::transaction(function () use ($map) {
            $now = now();

            foreach (DB::table('role_permissions')->get()->groupBy('role_id') as $roleId => $rows) {
                $result = [];
                foreach ($rows as $row) {
                    foreach ($map($row->permission) as $target) {
                        $result[$target === 'keep' ? $row->permission : $target] = true;
                    }
                }

                DB::table('role_permissions')->where('role_id', $roleId)->delete();
                DB::table('role_permissions')->insert(array_map(fn ($p) => [
                    'role_id' => $roleId, 'permission' => $p, 'created_at' => $now, 'updated_at' => $now,
                ], array_keys($result)));
            }

            foreach (DB::table('user_permissions')->get()->groupBy('user_id') as $userId => $rows) {
                $result = [];
                foreach ($rows as $row) {
                    foreach ($map($row->permission) as $target) {
                        $key = $target === 'keep' ? $row->permission : $target;
                        $result[$key] = isset($result[$key]) ? ($result[$key] && (bool) $row->is_granted) : (bool) $row->is_granted;
                    }
                }

                DB::table('user_permissions')->where('user_id', $userId)->delete();
                DB::table('user_permissions')->insert(array_map(fn ($p, $granted) => [
                    'user_id' => $userId, 'permission' => $p, 'is_granted' => $granted, 'created_at' => $now, 'updated_at' => $now,
                ], array_keys($result), array_values($result)));
            }
        });
    }
};
