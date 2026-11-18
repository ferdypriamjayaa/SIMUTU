<?php

namespace App\Support\Jotform;

use App\Models\Indicator;
use App\Models\IndicatorFormVersion;
use App\Models\JotformForm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only comparison of one indicator category's reference forms
 * (config/jotform.php) with Jotform, the exported definitions on disk and
 * SIMUTU's registry of imported forms.
 *
 * Forms are matched by Form ID only; titles are compared just to report
 * differences. Nothing is written anywhere.
 */
class JotformAudit
{
    public const REGISTERED = 'Sudah Terdaftar';

    public const UNREGISTERED = 'Belum Terdaftar';

    public const IMPORTED = 'Sudah Diimpor';

    public const DRAFT = 'Draft';

    public const CONFIRM = 'Perlu Konfirmasi';

    /**
     * @param  array<string, string>  $catalog  Form ID => reference title
     * @param  array<string, array<string, string>>|null  $jotform  Form ID => properties from the API, or null when not consulted
     * @param  list<array{directory: string, definition: ?JotformDefinition, error: ?string}>  $exports
     */
    public function __construct(
        private readonly string $category,
        private readonly array $catalog,
        private readonly ?array $jotform,
        private readonly array $exports,
    ) {}

    /**
     * @return list<array{form_id: ?string, jotform_title: ?string, indicator: ?string, category: string, status: string, notes: list<string>}>
     */
    public function rows(): array
    {
        $records = JotformForm::with(['indicator.category', 'formVersion'])->get()->keyBy('jotform_form_id');
        $exports = collect($this->exports)->filter(fn ($e) => $e['definition'] !== null)->keyBy(fn ($e) => $e['definition']->formId);
        $rows = [];

        foreach ($this->catalog as $formId => $referenceTitle) {
            $rows[] = $this->catalogRow((string) $formId, $referenceTitle, $records->get((string) $formId), $exports->get((string) $formId));
        }

        // SIMUTU indicators of this category that the catalogue does not account for.
        $indicators = Indicator::with('category')
            ->whereHas('category', fn ($q) => $q->where('code', $this->category))
            ->orderBy('id')
            ->get();

        foreach ($indicators as $indicator) {
            $record = $records->firstWhere('indicator_id', $indicator->id);

            if ($record === null) {
                $rows[] = $this->row(null, null, $indicator->name, self::CONFIRM, ['Indikator SIMUTU tanpa sumber Jotform (tidak ada di registry impor).']);
            } elseif (! array_key_exists($record->jotform_form_id, $this->catalog)) {
                $rows[] = $this->row($record->jotform_form_id, $record->title, $indicator->name, self::CONFIRM, [
                    'Form ID tidak ada di daftar referensi '.$this->category.' (config/jotform.php).',
                ]);
            }
        }

        // The same indicator name twice within the category.
        foreach ($indicators->groupBy(fn (Indicator $i) => self::normalize($i->name))->filter(fn ($group) => $group->count() > 1) as $group) {
            foreach ($rows as &$row) {
                if ($row['indicator'] !== null && self::normalize($row['indicator']) === self::normalize($group->first()->name)) {
                    $row['notes'][] = 'Potensi duplikasi: '.$group->count().' indikator '.$this->category.' bernama sama.';
                }
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * @param  list<array{status: string, indicator: ?string, form_id: ?string}>  $rows
     * @return array<string, int>
     */
    public function summary(array $rows): array
    {
        $catalogRows = array_slice($rows, 0, count($this->catalog));
        $count = fn (array $statuses, ?array $from = null) => count(array_filter($from ?? $rows, fn ($r) => in_array($r['status'], $statuses, true)));

        return [
            'Total formulir '.$this->label().' (daftar referensi)' => count($this->catalog),
            'Ditemukan di akun Jotform' => $this->jotform === null
                ? -1
                : count(array_intersect_key($this->jotform, $this->catalog)),
            'Total indikator '.$this->label().' di SIMUTU' => Indicator::whereHas('category', fn ($q) => $q->where('code', $this->category))->count(),
            'Sudah terdaftar' => $count([self::REGISTERED, self::DRAFT, self::IMPORTED], $catalogRows),
            'Belum terdaftar' => $count([self::UNREGISTERED], $catalogRows),
            'Sudah diimpor' => $count([self::DRAFT, self::IMPORTED], $catalogRows),
            'Masih draft' => $count([self::DRAFT], $catalogRows),
            'Perlu konfirmasi' => $count([self::CONFIRM]),
        ];
    }

    public function label(): string
    {
        return str_replace('_', ' ', $this->category);
    }

    /**
     * @param  array{directory: string, definition: ?JotformDefinition, error: ?string}|null  $export
     */
    private function catalogRow(string $formId, string $referenceTitle, ?JotformForm $record, ?array $export): array
    {
        $online = $this->jotform[$formId] ?? null;
        $definition = $export['definition'] ?? null;
        $jotformTitle = $online['title'] ?? $definition?->title ?? $referenceTitle;
        $indicator = $record?->indicator;
        $notes = [];

        if ($this->jotform !== null && $online === null) {
            $notes[] = 'Form ID tidak ditemukan di akun Jotform (terhapus atau di akun lain).';
        } elseif (($online['status'] ?? 'ENABLED') !== 'ENABLED') {
            $notes[] = 'Status di Jotform: '.$online['status'].'.';
        }

        $status = match (true) {
            $this->jotform !== null && $online === null => self::CONFIRM,
            $indicator === null => self::UNREGISTERED,
            $indicator->category?->code !== $this->category => self::CONFIRM,
            $record->formVersion === null => self::REGISTERED,
            $record->formVersion->status === IndicatorFormVersion::STATUS_DRAFT => self::DRAFT,
            default => self::IMPORTED,
        };

        if ($indicator !== null && $indicator->category?->code !== $this->category) {
            $notes[] = 'Terdaftar pada kategori '.($indicator->category?->code ?? '-').', bukan '.$this->category.'.';
        }

        // Where the definition stands on disk.
        if ($definition === null) {
            $notes[] = 'Definisi belum diekspor ke folder JOTFORM-EXPORT.';
        } elseif (! $definition->isImportable()) {
            $notes[] = "Ekspor {$export['directory']} tidak dapat diimpor: ".implode(' ', $definition->errors);
        } elseif ($indicator === null) {
            $notes[] = "Ekspor tersedia di {$export['directory']} (".count($definition->fields).' field), belum dipetakan ke indikator.';
        } elseif ($record->formVersion !== null && $record->definition_hash !== $definition->hash) {
            $notes[] = "Definisi di {$export['directory']} berbeda dengan yang terakhir diimpor.";
        }

        if ($record !== null && $record->formVersion === null && $indicator !== null) {
            $notes[] = 'Sudah dipetakan, belum diimpor.';
        }

        if ($record?->formVersion !== null) {
            $notes[] = 'Diimpor sebagai versi '.$record->formVersion->version.' ('.(IndicatorFormVersion::STATUSES[$record->formVersion->status] ?? $record->formVersion->status).').';
        }

        // Changed in Jotform after the last import.
        if ($online && $record?->imported_at && filled($online['updated_at'] ?? null)
            && Carbon::parse($online['updated_at'])->greaterThan($record->imported_at)) {
            $notes[] = 'Formulir diubah di Jotform setelah impor terakhir; ekspor ulang lalu periksa.';
        }

        if ($indicator !== null && self::normalize($jotformTitle) !== self::normalize($indicator->name)) {
            $notes[] = "Judul berbeda — Jotform: \"{$jotformTitle}\"; SIMUTU: \"{$indicator->name}\".";
        }

        return $this->row($formId, $jotformTitle, $indicator?->name, $status, $notes);
    }

    private function row(?string $formId, ?string $jotformTitle, ?string $indicator, string $status, array $notes): array
    {
        return [
            'form_id' => $formId,
            'jotform_title' => $jotformTitle !== null ? trim(preg_replace('/\s+/u', ' ', $jotformTitle)) : null,
            'indicator' => $indicator,
            'category' => $this->label(),
            'status' => $status,
            'notes' => $notes,
        ];
    }

    /** Case, spacing and trailing punctuation do not count as a different title. */
    public static function normalize(string $title): string
    {
        return rtrim(mb_strtolower(trim(preg_replace('/\s+/u', ' ', $title))), ' .');
    }

    /**
     * Jotform forms by Form ID.
     *
     * @param  list<array<string, string>>  $forms
     * @return array<string, array<string, string>>
     */
    public static function keyById(array $forms): array
    {
        return Collection::make($forms)->keyBy('id')->all();
    }
}
