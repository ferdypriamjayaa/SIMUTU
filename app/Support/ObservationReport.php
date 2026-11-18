<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Support\Xlsx\XlsxSheet;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Report layout of one indicator: a fixed set of leading columns, then one
 * column per form field. Nothing is hard-coded: the field columns come from
 * the indicator's Form Builder definitions.
 *
 * An indicator's form can change over time, so the columns are the fields
 * of every non-draft version, matched by field key: the newest version
 * decides order and headings, and fields that only exist in older versions
 * follow. Each answer is read and formatted with the field of the version
 * the observation itself was recorded on (or the first form, for records
 * from before form versions), so answers never move between columns.
 */
class ObservationReport
{
    /** Leading columns of every indicator's table. */
    public const LEADING = ['No', 'Tanggal Observasi', 'Unit', 'Diinput Oleh'];

    public const TRAILING = ['Versi Formulir'];

    /** @var array<string, string> field key => column heading */
    private array $columns = [];

    public function __construct(public readonly Indicator $indicator)
    {
        $versions = $indicator->formVersions()->with('fields.options')->get();
        $published = $versions->where('status', '!=', IndicatorFormVersion::STATUS_DRAFT);

        // Newest first (formVersions is ordered by version, descending).
        foreach ($published->isNotEmpty() ? $published : $versions as $version) {
            foreach ($version->activeFields() as $field) {
                $this->columns[$field->key] ??= self::heading($field);
            }
        }

        // The observed officer's name, when the form asks for it, comes right after "Diinput Oleh".
        if (($officer = $this->officerKey()) !== null) {
            $this->columns = [$officer => $this->columns[$officer]] + $this->columns;
        }
    }

    /** Field keys and label start that name the observed officer. */
    private const OFFICER_KEYS = ['officer_name', 'nama_petugas'];

    /**
     * The field holding the observed officer's name, if the form has one.
     */
    public function officerKey(): ?string
    {
        foreach ($this->columns as $key => $heading) {
            if (in_array($key, self::OFFICER_KEYS, true) || preg_match('/(^|— )nama petugas/i', $heading)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> field key => heading
     */
    public function fieldColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [...self::LEADING, ...array_values($this->columns), ...self::TRAILING];
    }

    /**
     * The observation as display text, one entry per heading ("-" when empty),
     * for the PDF report.
     *
     * @return list<string>
     */
    public function row(Observation $observation, int $number): array
    {
        $answers = $this->answers($observation);

        return [
            (string) $number,
            $observation->observation_date?->format('d-m-Y') ?? '-',
            $observation->unit?->name ?? '-',
            $observation->submitterName(),
            ...array_map(
                fn (string $key) => isset($answers[$key]) ? ObservationAnswers::format($answers[$key]['field'], $answers[$key]['raw']) : '-',
                array_keys($this->columns),
            ),
            $this->versionLabel($observation),
        ];
    }

    /**
     * The observation as typed spreadsheet cells, one per heading: dates as
     * dates, numbers as numbers, choices and Ya/Tidak as the label shown on
     * the form. Unanswered fields are empty cells.
     *
     * @return list<array{value: mixed, style: string}>
     */
    public function cells(Observation $observation, int $number): array
    {
        $answers = $this->answers($observation);

        return [
            XlsxSheet::cell($number, 'number'),
            XlsxSheet::cell($observation->observation_date?->copy()->startOfDay(), 'date'),
            XlsxSheet::cell($observation->unit?->name),
            XlsxSheet::cell($observation->submitterName()),
            ...array_map(
                fn (string $key) => isset($answers[$key]) ? self::typedCell($answers[$key]['field'], $answers[$key]['raw']) : XlsxSheet::cell(null),
                array_keys($this->columns),
            ),
            XlsxSheet::cell($this->versionLabel($observation)),
        ];
    }

    /**
     * Stored answers with the field they belong to, by field key.
     *
     * @return array<string, array{field: IndicatorField, raw: mixed}>
     */
    private function answers(Observation $observation): array
    {
        $version = ObservationAnswers::versionFor($observation);

        if ($version === null) {
            return [];
        }

        $raw = ObservationAnswers::raw($observation);
        $answers = [];

        foreach ($version->activeFields() as $field) {
            $answers[$field->key] = ['field' => $field, 'raw' => $raw[$field->key] ?? null];
        }

        return $answers;
    }

    /**
     * @return array{value: mixed, style: string}
     */
    public static function typedCell(IndicatorField $field, mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return XlsxSheet::cell(null);
        }

        $temporal = fn (array $formats, string $style) => ($date = self::parse((string) $raw, $formats))
            ? XlsxSheet::cell($date, $style)
            : XlsxSheet::cell((string) $raw);

        return match ($field->type) {
            IndicatorField::TYPE_NUMBER => is_numeric($raw)
                ? XlsxSheet::cell(str_contains((string) $raw, '.') || stripos((string) $raw, 'e') !== false ? (float) $raw : (int) $raw, 'number')
                : XlsxSheet::cell((string) $raw),
            IndicatorField::TYPE_DATE => $temporal(['Y-m-d', 'Y-m-d H:i:s'], 'date'),
            IndicatorField::TYPE_DATETIME => $temporal(['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i'], 'datetime'),
            // A time of day is a fraction of a day in Excel, without a date.
            IndicatorField::TYPE_TIME => ($time = self::parse((string) $raw, ['H:i', 'H:i:s']))
                ? XlsxSheet::cell(($time->hour * 3600 + $time->minute * 60 + $time->second) / 86400, 'time')
                : XlsxSheet::cell((string) $raw),
            // Ya/Tidak and choices exactly as the form showed them.
            default => XlsxSheet::cell(ObservationAnswers::format($field, $raw)),
        };
    }

    private static function heading(IndicatorField $field): string
    {
        return filled($field->section) ? "{$field->section} — {$field->label}" : $field->label;
    }

    private function versionLabel(Observation $observation): string
    {
        $version = ObservationAnswers::versionFor($observation);

        return $version ? 'Versi '.$version->version : '-';
    }

    /**
     * @param  list<string>  $formats
     */
    private static function parse(string $value, array $formats): ?Carbon
    {
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);

                if ($date !== null && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return null;
    }
}
