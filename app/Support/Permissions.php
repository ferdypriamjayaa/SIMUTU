<?php

namespace App\Support;

/**
 * The permission catalog and the rules that turn a role plus user overrides
 * into effective permissions. Identifiers are fixed in code; roles and users
 * only store which of them they hold.
 *
 * Precedence, highest first:
 *  1. System rules: an inactive account has nothing; the system role (Admin)
 *     always has everything; an inactive role grants nothing.
 *  2. An explicit user restriction (deny) removes the permission.
 *  3. An explicit user grant (allow) adds it.
 *  4. Otherwise the role's permissions apply.
 *
 * Observation access has two layers: a menu permission opens the page
 * (observations.create, reports.view) and the INM / IMP permissions decide
 * which categories may be seen, recorded, changed and exported there.
 */
final class Permissions
{
    /** Without it a user only sees data of their own unit. */
    public const VIEW_ALL_UNITS = 'data.view_all_units';

    /** Observation areas and the indicator categories each one covers. */
    public const AREAS = [
        'inm' => ['INM'],
        'imp' => ['IMP_RS'],
        'imp_unit' => ['IMP_UNIT'],
    ];

    /** Actions of an area: inm.view, imp.export, imp_unit.delete, ... (imp = IMP RS). */
    public const AREA_ACTIONS = ['view', 'create', 'edit', 'delete', 'export'];

    /**
     * Folders (groups and menus) and their permissions, as shown in the
     * permission tree. Every identifier appears exactly once.
     */
    public const TREE = [
        ['key' => 'menu', 'label' => 'Menu Utama', 'children' => [
            ['key' => 'menu.dashboard', 'label' => 'Dashboard', 'permissions' => [
                'dashboard.view' => 'Lihat dashboard',
            ]],
            ['key' => 'menu.input', 'label' => 'Input Observasi', 'permissions' => [
                'observations.create' => 'Buka menu Input Observasi',
            ]],
            ['key' => 'menu.reports', 'label' => 'Laporan Observasi', 'permissions' => [
                'reports.view' => 'Buka menu Laporan Observasi',
                self::VIEW_ALL_UNITS => 'Lihat data semua unit',
            ]],
        ]],
        ['key' => 'master', 'label' => 'Master Data', 'children' => [
            ['key' => 'master.indicators', 'label' => 'Indikator', 'permissions' => [
                'indicators.view' => 'Lihat indikator',
                'indicators.create' => 'Tambah indikator',
                'indicators.edit' => 'Ubah indikator dan formulirnya',
                'indicators.delete' => 'Hapus indikator',
            ]],
            ['key' => 'master.units', 'label' => 'Unit', 'permissions' => [
                'units.view' => 'Lihat unit',
                'units.create' => 'Tambah unit',
                'units.edit' => 'Ubah dan aktifkan/nonaktifkan unit',
                'units.delete' => 'Hapus unit',
            ]],
            ['key' => 'master.users', 'label' => 'Pengguna', 'permissions' => [
                'users.view' => 'Lihat pengguna',
                'users.create' => 'Tambah pengguna',
                'users.edit' => 'Ubah dan aktifkan/nonaktifkan pengguna',
                'users.delete' => 'Hapus pengguna',
                'permissions.manage' => 'Atur izin khusus per pengguna',
            ]],
            ['key' => 'master.roles', 'label' => 'Master Jabatan', 'permissions' => [
                'roles.view' => 'Lihat jabatan',
                'roles.manage' => 'Kelola jabatan dan izinnya',
            ]],
        ]],
        ['key' => 'integrations', 'label' => 'Integrasi Sistem', 'children' => [
            ['key' => 'integrations.mappings', 'label' => 'Mapping Indikator & Unit', 'permissions' => [
                'indicator_mappings.manage' => 'Kelola mapping indikator ke unit',
            ]],
            ['key' => 'integrations.hris', 'label' => 'Mapping Unit HRIS', 'permissions' => [
                'hris_mappings.view' => 'Lihat mapping unit HRIS',
                'hris_mappings.manage' => 'Tambah dan hapus mapping',
                'hris_mappings.sync' => 'Sinkronkan data HRIS dan dokter',
            ]],
            ['key' => 'integrations.jotform', 'label' => 'Impor Jotform', 'permissions' => [
                'jotform.view' => 'Lihat formulir Jotform',
                'jotform.manage' => 'Atur mapping formulir',
                'jotform.import' => 'Jalankan impor',
            ]],
        ]],
        ['key' => 'inm', 'label' => 'INM', 'permissions' => [
            'inm.view' => 'Lihat laporan dan detail observasi',
            'inm.create' => 'Input data',
            'inm.edit' => 'Ubah data',
            'inm.delete' => 'Hapus data',
            'inm.export' => 'Tarik data (Excel/PDF)',
        ]],
        ['key' => 'imp', 'label' => 'IMP RS', 'permissions' => [
            'imp.view' => 'Lihat laporan dan detail observasi',
            'imp.create' => 'Input data',
            'imp.edit' => 'Ubah data',
            'imp.delete' => 'Hapus data',
            'imp.export' => 'Tarik data (Excel/PDF)',
        ]],
        ['key' => 'imp_unit', 'label' => 'IMP Unit', 'permissions' => [
            'imp_unit.view' => 'Lihat laporan dan detail observasi',
            'imp_unit.create' => 'Input data',
            'imp_unit.edit' => 'Ubah data',
            'imp_unit.delete' => 'Hapus data',
            'imp_unit.export' => 'Tarik data (Excel/PDF)',
        ]],
    ];

    /**
     * Every identifier, in tree order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::labels());
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::labels());
    }

    public static function label(string $permission): string
    {
        return self::labels()[$permission] ?? $permission;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        static $labels = null;

        if ($labels === null) {
            $labels = [];
            $walk = function (array $nodes) use (&$walk, &$labels): void {
                foreach ($nodes as $node) {
                    $labels += $node['permissions'] ?? [];
                    $walk($node['children'] ?? []);
                }
            };
            $walk(self::TREE);
        }

        return $labels;
    }

    /**
     * Every permission below each folder of the tree, by folder key.
     *
     * @return array<string, list<string>>
     */
    public static function leavesByNode(): array
    {
        $result = [];
        $walk = function (array $node) use (&$walk, &$result): array {
            $leaves = array_keys($node['permissions'] ?? []);
            foreach ($node['children'] ?? [] as $child) {
                array_push($leaves, ...$walk($child));
            }

            return $result[$node['key']] = $leaves;
        };

        foreach (self::TREE as $node) {
            $walk($node);
        }

        return $result;
    }

    /**
     * The area (inm / imp) an indicator category belongs to.
     */
    public static function areaOf(?string $categoryCode): ?string
    {
        foreach (self::AREAS as $area => $codes) {
            if (in_array($categoryCode, $codes, true)) {
                return $area;
            }
        }

        return null;
    }

    /**
     * Effective permissions with the reason for each one.
     *
     * @param  list<string>  $rolePermissions
     * @param  array<string, bool>  $overrides  permission => true (grant) / false (restrict)
     * @return array<string, array{role: bool, override: ?bool, effective: bool, system: ?string}>
     */
    public static function resolve(array $rolePermissions, array $overrides, bool $roleIsSystem, bool $roleIsActive, bool $userIsActive = true): array
    {
        $result = [];

        foreach (self::all() as $permission) {
            $fromRole = in_array($permission, $rolePermissions, true);
            $override = $overrides[$permission] ?? null;

            [$effective, $system] = match (true) {
                ! $userIsActive => [false, 'Akun nonaktif'],
                $roleIsSystem => [true, 'Jabatan sistem selalu memiliki semua izin'],
                $override === false => [false, null],
                $override === true => [true, null],
                ! $roleIsActive => [false, 'Jabatan nonaktif'],
                default => [$fromRole, null],
            };

            $result[$permission] = ['role' => $fromRole, 'override' => $override, 'effective' => $effective, 'system' => $system];
        }

        return $result;
    }
}
