<?php

namespace App\Console\Commands;

use App\Services\JotformApi;
use App\Services\JotformApiException;
use App\Support\Jotform\JotformDefinition;
use App\Support\Jotform\JotformExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Saves one Jotform form definition (form properties and questions) as a
 * new folder in JOTFORM-EXPORT, in the layout the import page reads.
 *
 * Definitions only: no submissions are requested, submission counters are
 * left out of form.json, and nothing is written to the database. Importing
 * stays a separate, deliberate step on the Impor Jotform page.
 *
 *   php artisan jotform:export-definition 241342243354448
 */
class ExportJotformDefinition extends Command
{
    protected $signature = 'jotform:export-definition
        {form : Form ID Jotform}
        {--directory= : Nama folder baru (default: nomor urut berikutnya + judul singkat)}';

    protected $description = 'Ekspor definisi satu formulir Jotform (tanpa submission) ke folder JOTFORM-EXPORT';

    public function handle(JotformApi $api): int
    {
        $formId = (string) $this->argument('form');

        if (! preg_match('/^\d{5,32}$/', $formId)) {
            $this->error('Form ID harus berupa angka.');

            return self::FAILURE;
        }

        // One folder per form: never overwrite or duplicate an export.
        foreach (JotformExport::all() as $entry) {
            if ($entry['definition']?->formId === $formId) {
                $this->error("Formulir {$formId} sudah diekspor di folder {$entry['directory']}. Tidak ada yang diubah.");

                return self::FAILURE;
            }
        }

        try {
            $form = $api->form($formId);
            $questions = $api->questions($formId);
        } catch (JotformApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $root = JotformExport::path();
        $name = $this->option('directory') ?: $this->defaultDirectory($root, $form['title'] ?? $formId);

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $name)) {
            $this->error('Nama folder hanya boleh berisi huruf, angka, titik, garis bawah, dan strip.');

            return self::FAILURE;
        }

        $directory = $root.DIRECTORY_SEPARATOR.$name;

        if (file_exists($directory)) {
            $this->error("Folder {$name} sudah ada. Tidak ada yang diubah.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($directory);
        $json = fn (array $content) => json_encode(['content' => $content], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        File::put($directory.DIRECTORY_SEPARATOR.'form.json', $json($form));
        File::put($directory.DIRECTORY_SEPARATOR.'questions.json', $json($questions));

        try {
            $definition = JotformDefinition::fromDirectory($directory);
        } catch (RuntimeException $exception) {
            File::deleteDirectory($directory);
            $this->error('Definisi tidak dapat dibaca, folder tidak disimpan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Definisi \"{$definition->title}\" disimpan di JOTFORM-EXPORT/{$name}.");
        $this->line(count($definition->questions).' pertanyaan, '.count($definition->fields).' field dapat diimpor.');

        if (! $definition->isImportable()) {
            $this->warn('Belum dapat diimpor: '.implode(' ', $definition->errors));
        }

        $this->line('Belum ada yang diimpor. Petakan dan impor formulir ini dari halaman Impor Jotform.');

        return self::SUCCESS;
    }

    /**
     * Next number after the existing folders, then a short upper-case slug:
     * e.g. "14-ANGKA-PENURUNAN-VAS-TERHADAP".
     */
    private function defaultDirectory(string $root, string $title): string
    {
        $numbers = array_map(
            fn (string $path) => (int) basename($path),
            glob($root.DIRECTORY_SEPARATOR.'[0-9]*', GLOB_ONLYDIR) ?: [],
        );

        $slug = Str::upper(Str::slug(Str::words($title, 5, ''))) ?: 'FORM';

        return sprintf('%02d-%s', ($numbers === [] ? 0 : max($numbers)) + 1, $slug);
    }
}
