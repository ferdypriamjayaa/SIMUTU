<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Loads the reference data exported by `php artisan snapshot:export`
 * (database/seeders/snapshot/*.json): users with their existing passwords,
 * units, HRIS unit mappings, indicators and their forms, and the Jotform
 * imports (which the public patient survey is found through).
 *
 * The listed tables are replaced as a whole, keeping the original IDs so
 * that every link between them stays intact. To protect real data it stops
 * without changing anything when the database already has observations.
 *
 *   php artisan db:seed --class=SnapshotSeeder --force
 */
class SnapshotSeeder extends Seeder
{
    /** In load order: each table only points to tables above it. */
    public const TABLES = [
        'indicator_categories',
        'units',
        'users',
        'hris_unit_mappings',
        'indicators',
        'indicator_unit',
        'indicator_form_versions',
        'indicator_fields',
        'indicator_field_options',
        'jotform_forms',
    ];

    public static function directory(): string
    {
        return database_path('seeders'.DIRECTORY_SEPARATOR.'snapshot');
    }

    /** The pivot table has no id column. */
    public static function orderColumn(string $table): string
    {
        return $table === 'indicator_unit' ? 'indicator_id' : 'id';
    }

    public function run(): void
    {
        $data = $this->read();

        if (DB::table('observations')->exists()) {
            throw new RuntimeException(
                'Database ini sudah berisi data observasi. SnapshotSeeder dihentikan agar data tersebut tidak rusak; tidak ada yang diubah.'
            );
        }

        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use ($data) {
                // Children first, so nothing is left pointing at a removed row.
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }

                foreach (self::TABLES as $table) {
                    foreach (array_chunk($data[$table], 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach (self::TABLES as $table) {
            $this->command?->line(sprintf('  %-26s %5d baris', $table, count($data[$table])));
        }

        $this->command?->info('Snapshot dimuat. Login memakai NIP dan password yang sama seperti di aplikasi lokal.');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function read(): array
    {
        $data = [];

        foreach (self::TABLES as $table) {
            $file = self::directory().DIRECTORY_SEPARATOR.$table.'.json';

            if (! is_file($file)) {
                throw new RuntimeException("File snapshot {$file} tidak ditemukan. Jalankan `php artisan snapshot:export` di aplikasi lokal lalu unggah folder database/seeders/snapshot ke server.");
            }

            $rows = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

            if (! is_array($rows)) {
                throw new RuntimeException("File snapshot {$file} tidak valid.");
            }

            // Only the columns this database has and accepts values for (no
            // generated columns), so an older snapshot still loads.
            $columns = collect(Schema::getColumns($table))
                ->filter(fn (array $column) => empty($column['generation']))
                ->pluck('name')
                ->flip()
                ->all();
            $data[$table] = array_map(fn (array $row) => array_intersect_key($row, $columns), array_values($rows));
        }

        return $data;
    }
}
