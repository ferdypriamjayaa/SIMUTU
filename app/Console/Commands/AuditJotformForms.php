<?php

namespace App\Console\Commands;

use App\Services\JotformApi;
use App\Services\JotformApiException;
use App\Support\Jotform\JotformAudit;
use App\Support\Jotform\JotformExport;
use Illuminate\Console\Command;

/**
 * Read-only audit: are all reference Jotform forms of a category registered,
 * imported and published in SIMUTU? Reads the Jotform form list (form
 * properties only, never submissions), the exported definitions on disk
 * and the database. Writes nothing.
 *
 *   php artisan jotform:audit                 IMP RS, with the Jotform API
 *   php artisan jotform:audit --offline       without calling Jotform
 */
class AuditJotformForms extends Command
{
    protected $signature = 'jotform:audit
        {--category=IMP_RS : Kode kategori indikator pada config/jotform.php}
        {--offline : Jangan memanggil API Jotform; gunakan folder ekspor dan database saja}';

    protected $description = 'Audit kelengkapan formulir Jotform terhadap indikator SIMUTU (baca saja)';

    public function handle(JotformApi $api): int
    {
        $category = strtoupper((string) $this->option('category'));
        $catalog = config("jotform.catalog.{$category}");

        if (! is_array($catalog) || $catalog === []) {
            $this->error("Tidak ada daftar referensi untuk kategori {$category} di config/jotform.php.");

            return self::FAILURE;
        }

        $jotform = null;

        if (! $this->option('offline')) {
            try {
                $jotform = JotformAudit::keyById($api->forms());
                $this->info('Daftar formulir Jotform: '.count($jotform).' formulir di akun.');
            } catch (JotformApiException $exception) {
                $this->warn($exception->getMessage().' Audit dilanjutkan tanpa data Jotform.');
            }
        }

        $audit = new JotformAudit($category, $catalog, $jotform, JotformExport::all());
        $rows = $audit->rows();

        $this->newLine();
        $this->line("<options=bold>Audit formulir Jotform {$audit->label()} vs SIMUTU</>");
        $this->table(
            ['No', 'Judul formulir Jotform', 'Form ID', 'Judul indikator SIMUTU', 'Kategori', 'Status', 'Keterangan'],
            array_map(fn (array $row, int $i) => [
                $i + 1,
                $row['jotform_title'] ?? '-',
                $row['form_id'] ?? '-',
                $row['indicator'] ?? '-',
                $row['category'],
                $row['status'],
                implode("\n", $row['notes']) ?: '-',
            ], $rows, array_keys($rows)),
        );

        $this->line('<options=bold>Ringkasan</>');
        $this->table(['', 'Jumlah'], collect($audit->summary($rows))
            ->map(fn (int $value, string $label) => [$label, $value < 0 ? 'tidak diperiksa (--offline / API gagal)' : $value])
            ->values()
            ->all());

        $missing = array_filter($rows, fn ($row) => $row['status'] === JotformAudit::UNREGISTERED);

        if ($missing !== []) {
            $this->warn('Belum masuk SIMUTU:');
            foreach ($missing as $row) {
                $this->line(" - {$row['jotform_title']} ({$row['form_id']})");
            }
        }

        return self::SUCCESS;
    }
}
