<?php

namespace App\Support;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Support\Xlsx\XlsxSheet;
use App\Support\Xlsx\XlsxWorkbook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The report of one indicator as an Excel workbook: a "Ringkasan" sheet with
 * the filters and counts, then the indicator's sheet with one observation per
 * row and one column per form field. Indicators are never combined; "every
 * unit" means every observation of the same indicator.
 *
 * Only counts are summarised; no achievement percentage is calculated,
 * because no formula has been defined for any indicator.
 */
class ObservationWorkbook
{
    /** Title, category, period & unit, a spacer, then the column headings. */
    private const HEADER_ROW = 5;

    private const TITLE_HEIGHT = 24;

    private const SPACER_HEIGHT = 6;

    /**
     * @param  Builder<Observation>  $observations  already limited to what the user may see and to the filters
     */
    public function __construct(
        private readonly Builder $observations,
        private readonly ReportFilters $report,
    ) {}

    public static function categoryLabel(?Indicator $indicator): string
    {
        $category = $indicator?->category;

        return $category ? $category->name.' ('.str_replace('_', ' ', $category->code).')' : '-';
    }

    public static function periodLabel(array $filters): string
    {
        $start = isset($filters['start_date']) ? Carbon::parse($filters['start_date'])->format('d-m-Y') : null;
        $end = isset($filters['end_date']) ? Carbon::parse($filters['end_date'])->format('d-m-Y') : null;

        return match (true) {
            $start === null && $end === null => 'Semua tanggal',
            $end === null => "{$start} s.d. sekarang",
            $start === null => "s.d. {$end}",
            default => "{$start} s.d. {$end}",
        };
    }

    public function save(string $path): void
    {
        $indicator = $this->report->indicator;
        $workbook = new XlsxWorkbook('Laporan Observasi Mutu');
        $summary = $workbook->addSheet('Ringkasan');
        $sheet = $workbook->addSheet($indicator->name);

        $this->fillIndicatorSheet($sheet, $indicator);
        $this->fillSummary($summary, $sheet->name);
        $workbook->save($path);
    }

    private function fillIndicatorSheet(XlsxSheet $sheet, Indicator $indicator): int
    {
        $report = new ObservationReport($indicator);
        $headings = $report->headings();

        $sheet->addRow([XlsxSheet::cell($indicator->name, 'title')], measure: false, height: self::TITLE_HEIGHT);
        $sheet->addRow([XlsxSheet::cell('Kategori: '.self::categoryLabel($indicator), 'subtitle')], measure: false);
        $sheet->addRow([XlsxSheet::cell(
            'Periode: '.self::periodLabel($this->report->scope()).'     Unit: '.($this->report->unit?->name ?? 'Semua unit'),
            'subtitle',
        )], measure: false);
        $sheet->blankRow(self::SPACER_HEIGHT);
        $sheet->addRow(array_map(fn (string $heading) => XlsxSheet::cell($heading, 'header'), $headings), header: true);

        $number = 0;
        $observations = (clone $this->observations)
            ->where('indicator_id', $indicator->id)
            ->withAnswers()
            ->with(['unit', 'user'])
            ->reorder()
            ->orderBy('observation_date')
            ->orderBy('id');

        foreach ($observations->lazy(500) as $observation) {
            $number++;
            $sheet->addRow($report->cells($observation, $number), striped: $number % 2 === 0);
        }

        if ($number === 0) {
            $sheet->addRow([XlsxSheet::cell('Tidak ada data observasi pada filter ini.', 'note')], measure: false);
        }

        $sheet->setWidth(1, 6);
        $sheet->freezeRows(self::HEADER_ROW);
        $sheet->autoFilter(self::HEADER_ROW, count($headings), self::HEADER_ROW + $number);

        return $number;
    }

    private function fillSummary(XlsxSheet $sheet, string $dataSheet): void
    {
        $label = fn (string $text) => XlsxSheet::cell($text, 'label');
        $indicator = $this->report->indicator;
        $observations = (clone $this->observations)->reorder();

        $sheet->addRow([XlsxSheet::cell('Laporan Observasi Mutu', 'title')], measure: false, height: self::TITLE_HEIGHT);
        $sheet->addRow([XlsxSheet::cell('RSU Syifa Medika · Sistem Informasi Mutu (SIMUTU)', 'subtitle')], measure: false);
        $sheet->blankRow();
        $sheet->addRow([$label('Kategori'), XlsxSheet::cell(self::categoryLabel($indicator))]);
        $sheet->addRow([$label('Indikator'), XlsxSheet::cell($indicator->name)]);
        $sheet->addRow([$label('Periode'), XlsxSheet::cell(self::periodLabel($this->report->scope()))]);
        $sheet->addRow([$label('Unit'), XlsxSheet::cell($this->report->unit?->name ?? 'Semua unit')]);
        $sheet->addRow([$label('Jumlah Observasi'), XlsxSheet::cell((clone $observations)->count(), 'number')]);
        $sheet->addRow([$label('Unit Melapor'), XlsxSheet::cell((clone $observations)->distinct()->count('unit_id'), 'number')]);
        $sheet->addRow([$label('Dicetak'), XlsxSheet::cell(now(), 'datetime')]);
        $row = $sheet->addRow([$label('Data'), XlsxSheet::cell($dataSheet, 'link')]);
        $sheet->linkToSheet($row, 2, $dataSheet, $dataSheet);
        $sheet->blankRow();
        $sheet->addRow([XlsxSheet::cell(
            'Ringkasan ini hanya menghitung jumlah observasi. Capaian indikator tidak dihitung karena rumusnya belum ditetapkan.',
            'note',
        )], measure: false);

        $sheet->setWidth(1, 18);
    }
}
