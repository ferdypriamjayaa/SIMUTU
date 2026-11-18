<?php

namespace App\Console\Commands;

use Database\Seeders\SnapshotSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Writes the reference data of this database (users, units, indicators,
 * forms, Jotform imports) to database/seeders/snapshot/*.json, so that
 * SnapshotSeeder can load the same data on a server.
 *
 * Observations, patient answers, sessions and cache are never exported.
 */
class ExportSnapshot extends Command
{
    protected $signature = 'snapshot:export';

    protected $description = 'Ekspor data referensi (user, unit, indikator, formulir) ke database/seeders/snapshot untuk SnapshotSeeder';

    public function handle(): int
    {
        $directory = SnapshotSeeder::directory();
        File::ensureDirectoryExists($directory);

        foreach (SnapshotSeeder::TABLES as $table) {
            $rows = DB::table($table)->orderBy(SnapshotSeeder::orderColumn($table))->get()
                ->map(fn ($row) => (array) $row)
                ->all();

            File::put(
                $directory.DIRECTORY_SEPARATOR.$table.'.json',
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
            );

            $this->line(sprintf('  %-26s %5d baris', $table, count($rows)));
        }

        $this->info('Snapshot tersimpan di '.$directory);
        $this->warn('Folder ini berisi data user (NIP, nama, hash password): jangan di-commit ke repository publik.');

        return self::SUCCESS;
    }
}
