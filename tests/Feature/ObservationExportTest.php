<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorField;
use App\Models\IndicatorFormVersion;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationAnswers;
use App\Support\ObservationForms;
use App\Support\Xlsx\XlsxWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Observation report of one indicator as an .xlsx workbook: a summary sheet
 * plus the indicator's sheet, one observation per row, one column per field.
 */
class ObservationExportTest extends TestCase
{
    use CreatesInmIndicators, ReadsXlsx, RefreshDatabase;

    /** Header row of every indicator sheet (title, subtitle, blank above it). */
    private const HEADER = 5;

    /** Excel serial of 2026-11-10. */
    private const NOV_10 = 46336;

    /** @var array<string, Indicator> */
    private array $inm;

    private Unit $ns3a;

    private Unit $igd;

    private User $picUnit;

    private User $picMutu;

    private Indicator $impRs;

    private Indicator $impUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inm = $this->createInmIndicators();
        $this->ns3a = Unit::create(['code' => 'NS3A', 'name' => 'Rawat Inap NS3A', 'is_active' => true]);
        $this->igd = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->assignIndicators($this->ns3a, $this->inm);
        $this->assignIndicators($this->igd, $this->inm);
        $this->picUnit = User::factory()->picUnit($this->ns3a)->create(['name' => 'Perawat NS3A']);
        $this->picMutu = User::factory()->picMutu()->create();

        // IMP RS: a Form Builder form with every field type.
        $this->impRs = $this->indicator('IMP_RS', 'Indikator Mutu Pelayanan Rumah Sakit', 'Kepatuhan Pengisian Rekam Medis Lengkap');
        $this->version($this->impRs, 1, IndicatorFormVersion::STATUS_PUBLISHED, [
            'nama_petugas' => ['type' => 'text', 'label' => 'Nama Petugas'],
            'nama_pasien' => ['type' => 'text', 'label' => 'Nama Pasien'],
            'jumlah_berkas' => ['type' => 'number', 'label' => 'Jumlah Berkas'],
            'skor' => ['type' => 'number', 'label' => 'Skor', 'allow_decimal' => true],
            'tanggal_masuk' => ['type' => 'date', 'label' => 'Tanggal Masuk'],
            'jam_visite' => ['type' => 'time', 'label' => 'Jam Visite'],
            'waktu_pulang' => ['type' => 'datetime', 'label' => 'Waktu Pulang'],
            'ruang' => ['type' => 'select', 'label' => 'Ruang', 'options' => ['ns3a' => 'Ruang NS3A', 'icu' => 'ICU']],
            'shift' => ['type' => 'radio', 'label' => 'Shift', 'options' => ['p' => 'Pagi', 'm' => 'Malam']],
            'dokumen' => ['type' => 'checkbox', 'label' => 'Dokumen Lengkap', 'section' => 'Kelengkapan', 'options' => ['resume' => 'Resume Medis', 'cppt' => 'CPPT', 'ic' => 'Informed Consent']],
            'ttd' => ['type' => 'yes_no', 'label' => 'Tanda Tangan DPJP', 'section' => 'Kelengkapan'],
            'catatan' => ['type' => 'textarea', 'label' => 'Catatan'],
        ]);

        // IMP Unit: a short form with two fields.
        $this->impUnit = $this->indicator('IMP_UNIT', 'Indikator Mutu Pelayanan Unit', 'Kepatuhan Cuci Tangan Unit');
        $this->version($this->impUnit, 1, IndicatorFormVersion::STATUS_PUBLISHED, [
            'patuh' => ['type' => 'yes_no', 'label' => 'Patuh'],
            'momen' => ['type' => 'select', 'label' => 'Momen', 'options' => ['1' => 'Sebelum kontak pasien', '2' => 'Sesudah kontak pasien']],
        ]);
    }

    // --- helpers ------------------------------------------------------------------------

    private function indicator(string $code, string $categoryName, string $name): Indicator
    {
        $categoryId = DB::table('indicator_categories')->where('code', $code)->value('id')
            ?? DB::table('indicator_categories')->insertGetId([
                'code' => $code, 'slug' => strtolower($code), 'name' => $categoryName, 'created_at' => now(), 'updated_at' => now(),
            ]);

        return Indicator::create(['indicator_category_id' => $categoryId, 'name' => $name, 'is_active' => true]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields  key => type, label, options, section, allow_decimal
     */
    private function version(Indicator $indicator, int $number, string $status, array $fields): IndicatorFormVersion
    {
        $version = IndicatorFormVersion::create(['indicator_id' => $indicator->id, 'version' => $number, 'status' => $status]);
        $order = 0;

        foreach ($fields as $key => $spec) {
            $field = IndicatorField::create([
                'indicator_form_version_id' => $version->id,
                'key' => $key,
                'label' => $spec['label'],
                'type' => $spec['type'],
                'section' => $spec['section'] ?? null,
                'is_required' => false,
                'is_active' => $spec['is_active'] ?? true,
                'sort_order' => $order++,
                'allow_decimal' => $spec['allow_decimal'] ?? false,
            ]);

            foreach (array_keys($spec['options'] ?? []) as $i => $value) {
                $field->options()->create(['value' => (string) $value, 'label' => $spec['options'][$value], 'sort_order' => $i]);
            }
        }

        return $version->load('fields.options');
    }

    /**
     * An observation stored exactly as the observation form stores it.
     *
     * @param  array<string, mixed>  $answers
     */
    private function observe(Indicator $indicator, IndicatorFormVersion $version, Unit $unit, string $date, array $answers): Observation
    {
        $observation = Observation::create([
            'indicator_id' => $indicator->id,
            'indicator_form_version_id' => $version->id,
            'unit_id' => $unit->id,
            'user_id' => $this->picUnit->id,
            'observation_date' => $date,
        ]);
        ObservationAnswers::sync($observation, $version->load('fields.options'), $answers);

        return $observation;
    }

    /** A complete report: one indicator (identification unless given) and the whole of 2026. */
    private function export(array $query = [], ?User $user = null, string $format = 'excel'): TestResponse
    {
        return $this->actingAs($user ?? $this->picMutu)->get(route('observations.export', [
            'format' => $format,
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            ...$query,
        ]));
    }

    /**
     * @return array<string, array<int, array<string, array{value: string|float|null, style: int, type: string}>>>
     */
    private function workbook(array $query = []): array
    {
        return $this->xlsxSheets($this->export($query)->assertOk()->streamedContent());
    }

    private function submitIdentification(Unit $unit, string $date, array $overrides = []): void
    {
        $user = User::factory()->picUnit($unit)->create();

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id,
            'observation_date' => $date,
            ...ObservationTest::validSubmissions()['identifikasi pasien'][1],
            ...$overrides,
        ])->assertSessionHasNoErrors();
    }

    private function rowsAfterHeader(array $sheet): array
    {
        return array_values(array_filter($sheet, fn (int $row) => $row > self::HEADER, ARRAY_FILTER_USE_KEY));
    }

    // --- file ---------------------------------------------------------------------------

    public function test_download_is_a_real_xlsx_workbook(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-10');

        $response = $this->export()->assertOk();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/filename=laporan-kepatuhan-identifikasi-pasien-\d{8}-\d{6}\.xlsx/', $response->headers->get('Content-Disposition'));

        $bytes = $response->streamedContent();
        $this->assertStringStartsWith("PK\x03\x04", $bytes);
        $this->assertSame((string) strlen($bytes), $response->headers->get('Content-Length'));

        $parts = $this->xlsxParts($bytes);
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml'] as $part) {
            $this->assertArrayHasKey($part, $parts);
        }
        $this->assertStringContainsString('spreadsheetml.sheet.main+xml', $parts['[Content_Types].xml']);

        // The temporary file is removed once sent.
        $this->assertSame([], glob(sys_get_temp_dir().'/simutu-xlsx-*') ?: []);
    }

    public function test_each_category_exports_one_indicator_with_one_column_per_field(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-10');
        $this->observe($this->impRs, $this->impRs->formVersions()->first(), $this->ns3a, '2026-11-10', ['nama_petugas' => 'Rina']);
        $this->observe($this->impUnit, $this->impUnit->formVersions()->first(), $this->igd, '2026-11-11', ['patuh' => '1', 'momen' => '2']);

        $inm = $this->workbook();
        $rs = $this->workbook(['indicator_id' => $this->impRs->id]);
        $unit = $this->workbook(['indicator_id' => $this->impUnit->id]);

        // Never more than the chosen indicator.
        $this->assertSame(['Ringkasan', 'Kepatuhan Identifikasi Pasien'], array_keys($inm));
        $this->assertSame(['Ringkasan', 'Kepatuhan Pengisian Rekam Medis'], array_keys($rs));
        $this->assertSame(['Ringkasan', 'Kepatuhan Cuci Tangan Unit'], array_keys($unit));

        $leading = ['No', 'Tanggal Observasi', 'Unit', 'Diinput Oleh'];
        $this->assertSame([
            ...$leading, 'Nama Petugas yang Diobservasi', 'Nama Pasien',
            'Aktivitas yang Diobservasi — Sebelum pemberian obat atau injeksi', 'Aktivitas yang Diobservasi — Sebelum tindakan atau prosedur',
            'Kriteria Identifikasi Pasien — Nama pasien', 'Kriteria Identifikasi Pasien — Tanggal lahir', 'Catatan',
        ], $this->xlsxValues($inm['Kepatuhan Identifikasi Pasien'][self::HEADER]));
        $this->assertSame([
            ...$leading, 'Nama Petugas', 'Nama Pasien', 'Jumlah Berkas', 'Skor', 'Tanggal Masuk', 'Jam Visite', 'Waktu Pulang', 'Ruang', 'Shift',
            'Kelengkapan — Dokumen Lengkap', 'Kelengkapan — Tanda Tangan DPJP', 'Catatan',
        ], $this->xlsxValues($rs['Kepatuhan Pengisian Rekam Medis'][self::HEADER]));
        $this->assertSame([...$leading, 'Patuh', 'Momen'], $this->xlsxValues($unit['Kepatuhan Cuci Tangan Unit'][self::HEADER]));

        // Nothing is folded into a "Detail" column.
        foreach ([$inm, $rs, $unit] as $sheets) {
            foreach ($sheets as $rows) {
                foreach ($rows as $row) {
                    $this->assertNotContains('Detail', $this->xlsxValues($row));
                }
            }
        }

        // Every answer in its own cell.
        $this->assertSame(
            [1.0, (float) self::NOV_10, 'Rawat Inap NS3A', null, 'Taufik Hidayat', 'Norbayah', 'Tidak', 'Ya', 'Ya', 'Ya', null],
            array_replace($this->xlsxValues($inm['Kepatuhan Identifikasi Pasien'][6]), [3 => null]),
        );
        $this->assertSame([1.0, (float) (self::NOV_10 + 1), 'Instalasi Gawat Darurat', 'Perawat NS3A', 'Ya', 'Sesudah kontak pasien'],
            $this->xlsxValues($unit['Kepatuhan Cuci Tangan Unit'][6]));

        // Summary: category, indicator, period, unit, counts, link to the data sheet.
        $summary = $rs['Ringkasan'];
        $this->assertSame('Laporan Observasi Mutu', $summary[1]['A']['value']);
        $this->assertSame(['Kategori', 'Indikator Mutu Pelayanan Rumah Sakit (IMP RS)'], $this->xlsxValues($summary[4]));
        $this->assertSame(['Indikator', 'Kepatuhan Pengisian Rekam Medis Lengkap'], $this->xlsxValues($summary[5]));
        $this->assertSame(['Periode', '01-01-2026 s.d. 31-12-2026'], $this->xlsxValues($summary[6]));
        $this->assertSame(['Unit', 'Semua unit'], $this->xlsxValues($summary[7]));
        $this->assertSame(['Jumlah Observasi', 1.0], $this->xlsxValues($summary[8]));
        $this->assertSame(['Unit Melapor', 1.0], $this->xlsxValues($summary[9]));
        $this->assertStringContainsString('<hyperlink ref="B11" location="\'Kepatuhan Pengisian Rekam Medis\'!A1"',
            $this->xlsxSheetXml($this->export(['indicator_id' => $this->impRs->id])->streamedContent(), 'Ringkasan')->asXML());

        // Counts only: no achievement percentage anywhere.
        $all = json_encode([$inm, $rs, $unit]);
        $this->assertStringNotContainsString('%', $all);
        $this->assertStringContainsString('Capaian indikator tidak dihitung', $all);
    }

    public function test_answers_keep_their_type_and_the_label_shown_on_the_form(): void
    {
        $this->observe($this->impRs, $this->impRs->formVersions()->first(), $this->ns3a, '2026-11-10', [
            'nama_petugas' => 'Rina Mardiana',
            'nama_pasien' => '=HYPERLINK("http://x","klik")',
            'jumlah_berkas' => '12',
            'skor' => '87.5',
            'tanggal_masuk' => '2026-11-10',
            'jam_visite' => '14:30',
            'waktu_pulang' => '2026-11-11T09:15',
            'ruang' => 'icu',
            'shift' => 'm',
            'dokumen' => ['resume', 'ic'],
            'ttd' => '0',
            'catatan' => "Baris satu\nBaris dua",
        ]);

        $bytes = $this->export(['indicator_id' => $this->impRs->id])->streamedContent();
        $row = $this->xlsxSheets($bytes)['Kepatuhan Pengisian Rekam Medis'][6];

        $this->assertSame(['text', 'Rina Mardiana'], [$row['E']['type'], $row['E']['value']]);
        // User text is stored as text, never as a formula.
        $this->assertSame(['text', '=HYPERLINK("http://x","klik")'], [$row['F']['type'], $row['F']['value']]);
        $this->assertStringNotContainsString('<f>', $this->xlsxParts($bytes)['xl/worksheets/sheet2.xml']);

        $this->assertSame(['number', 12.0], [$row['G']['type'], $row['G']['value']]);
        $this->assertSame(['number', 87.5], [$row['H']['type'], $row['H']['value']]);

        // Dates and times are real Excel values with a date/time format.
        $this->assertSame([(float) self::NOV_10, XlsxWorkbook::STYLES['date']], [$row['I']['value'], $row['I']['style']]);
        $this->assertEqualsWithDelta(14.5 / 24, $row['J']['value'], 1e-9);
        $this->assertSame(XlsxWorkbook::STYLES['time'], $row['J']['style']);
        $this->assertEqualsWithDelta(self::NOV_10 + 1 + (9.25 / 24), $row['K']['value'], 1e-9);
        $this->assertSame(XlsxWorkbook::STYLES['datetime'], $row['K']['style']);

        // Choices and Ya/Tidak as labelled on the form.
        $this->assertSame('ICU', $row['L']['value']);
        $this->assertSame('Malam', $row['M']['value']);
        $this->assertSame('Resume Medis, Informed Consent', $row['N']['value']);
        $this->assertSame('Tidak', $row['O']['value']);
        $this->assertSame("Baris satu\nBaris dua", $row['P']['value']);
        // No form version column: the last answer is the last column.
        $this->assertArrayNotHasKey('Q', $row);

        // The observation date column is a date too.
        $this->assertSame([(float) self::NOV_10, XlsxWorkbook::STYLES['date']], [$row['B']['value'], $row['B']['style']]);
    }

    public function test_indicators_with_different_numbers_of_fields_keep_answers_in_their_columns(): void
    {
        $impRsVersion = $this->impRs->formVersions()->first();
        $impUnitVersion = $this->impUnit->formVersions()->first();

        // Several observations with some answers left empty.
        $this->observe($this->impRs, $impRsVersion, $this->ns3a, '2026-11-12', ['nama_petugas' => 'Kedua', 'ttd' => '1']);
        $this->observe($this->impRs, $impRsVersion, $this->ns3a, '2026-11-10', ['nama_petugas' => 'Pertama', 'catatan' => 'akhir', 'jumlah_berkas' => '0']);
        $this->observe($this->impUnit, $impUnitVersion, $this->igd, '2026-11-10', ['momen' => '1']);

        $rsSheet = $this->workbook(['indicator_id' => $this->impRs->id])['Kepatuhan Pengisian Rekam Medis'];
        $rs = $this->rowsAfterHeader($rsSheet);
        $unit = $this->rowsAfterHeader($this->workbook(['indicator_id' => $this->impUnit->id])['Kepatuhan Cuci Tangan Unit']);

        // One observation per row, by date; every row as wide as the header.
        $this->assertCount(2, $rs);
        $this->assertSame(['Pertama', 0.0, null, 'akhir'], [$rs[0]['E']['value'], $rs[0]['G']['value'], $rs[0]['O']['value'], $rs[0]['P']['value']]);
        $this->assertSame(['Kedua', null, 'Ya', null], [$rs[1]['E']['value'], $rs[1]['G']['value'], $rs[1]['O']['value'], $rs[1]['P']['value']]);
        $this->assertCount(count($rsSheet[self::HEADER]), $rs[0]);
        $this->assertSame([null, 'Sebelum kontak pasien'], array_slice($this->xlsxValues($unit[0]), 4));
    }

    public function test_historical_observations_follow_the_form_they_were_recorded_on(): void
    {
        // Version 1 (archived): a, b. Version 2 (published): b renamed, c added, a dropped.
        $indicator = $this->indicator('IMP_RS', 'Indikator Mutu Pelayanan Rumah Sakit', 'Kepatuhan Berubah Formulir');
        $v1 = $this->version($indicator, 1, IndicatorFormVersion::STATUS_ARCHIVED, [
            'a' => ['type' => 'text', 'label' => 'Kolom A'],
            'b' => ['type' => 'select', 'label' => 'Kolom B Lama', 'options' => ['x' => 'Pilihan X lama']],
        ]);
        $v2 = $this->version($indicator, 2, IndicatorFormVersion::STATUS_PUBLISHED, [
            'b' => ['type' => 'select', 'label' => 'Kolom B', 'options' => ['x' => 'Pilihan X baru']],
            'c' => ['type' => 'yes_no', 'label' => 'Kolom C'],
            'a' => ['type' => 'text', 'label' => 'Kolom A', 'is_active' => false],
        ]);
        // A draft is never part of the report.
        $this->version($indicator, 3, IndicatorFormVersion::STATUS_DRAFT, ['d' => ['type' => 'text', 'label' => 'Kolom Draf']]);

        $this->observe($indicator, $v1, $this->ns3a, '2026-11-01', ['a' => 'lama', 'b' => 'x']);
        $this->observe($indicator, $v2, $this->ns3a, '2026-11-02', ['b' => 'x', 'c' => '1']);

        $sheet = $this->workbook(['indicator_id' => $indicator->id])['Kepatuhan Berubah Formulir'];

        // Newest version's order and labels, then fields only older versions had.
        $this->assertSame(['Kolom B', 'Kolom C', 'Kolom A'], array_slice($this->xlsxValues($sheet[self::HEADER]), 4));
        // Each answer is labelled by the version it was recorded on.
        $this->assertSame(['Pilihan X lama', null, 'lama'], array_slice($this->xlsxValues($sheet[6]), 4));
        $this->assertSame(['Pilihan X baru', 'Ya', null], array_slice($this->xlsxValues($sheet[7]), 4));
    }

    public function test_records_from_before_form_versions_are_exported(): void
    {
        $legacy = Observation::create([
            'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $this->ns3a->id,
            'user_id' => $this->picUnit->id,
            'observation_date' => '2026-11-10',
            'officer_name' => 'Petugas Lama',
            'patient_name' => 'Pasien Lama',
            'medication_compliant' => true,
            'procedure_compliant' => false,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);

        $row = $this->workbook()['Kepatuhan Identifikasi Pasien'][6];

        $this->assertSame(['Petugas Lama', 'Pasien Lama', 'Ya', 'Tidak', 'Ya', 'Ya', null], array_slice($this->xlsxValues($row), 4));
        $this->assertNull($legacy->fresh()->indicator_form_version_id);
    }

    public function test_date_unit_and_indicator_filters_are_applied(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-01', ['patient_name' => 'Pasien Awal NS3A']);
        $this->submitIdentification($this->ns3a, '2026-11-10', ['patient_name' => 'Pasien Tengah NS3A']);
        $this->submitIdentification($this->igd, '2026-11-10', ['patient_name' => 'Pasien IGD']);
        $this->observe($this->impUnit, $this->impUnit->formVersions()->first(), $this->ns3a, '2026-11-10', ['patuh' => '1']);

        $patients = fn (array $sheets) => array_map(fn (array $row) => $row['F']['value'], $this->rowsAfterHeader($sheets['Kepatuhan Identifikasi Pasien'] ?? []));

        // Every unit: the same indicator's observations of all units, nothing of other indicators.
        $all = $this->workbook(['unit_id' => 'semua']);
        $this->assertSame(['Pasien Awal NS3A', 'Pasien Tengah NS3A', 'Pasien IGD'], $patients($all));
        $this->assertSame(['Ringkasan', 'Kepatuhan Identifikasi Pasien'], array_keys($all));
        $this->assertSame(['Unit Melapor', 2.0], $this->xlsxValues($all['Ringkasan'][9]));

        $byDate = $this->workbook(['start_date' => '2026-11-05', 'end_date' => '2026-11-30']);
        $this->assertSame(['Pasien Tengah NS3A', 'Pasien IGD'], $patients($byDate));
        $this->assertStringContainsString('Periode: 05-11-2026 s.d. 30-11-2026', $byDate['Kepatuhan Identifikasi Pasien'][3]['A']['value']);

        $byUnit = $this->workbook(['unit_id' => $this->ns3a->id]);
        $this->assertSame(['Pasien Awal NS3A', 'Pasien Tengah NS3A'], $patients($byUnit));
        $this->assertSame(['Unit', 'Rawat Inap NS3A'], $this->xlsxValues($byUnit['Ringkasan'][7]));
        $this->assertSame(['Unit Melapor', 1.0], $this->xlsxValues($byUnit['Ringkasan'][9]));

        // A filter without matches still yields a valid workbook with the indicator's columns.
        $empty = $this->workbook(['indicator_id' => $this->impRs->id, 'start_date' => '2026-12-01']);
        $this->assertSame('Nama Petugas', $empty['Kepatuhan Pengisian Rekam Medis'][self::HEADER]['E']['value']);
        $this->assertSame('Tidak ada data observasi pada filter ini.', $empty['Kepatuhan Pengisian Rekam Medis'][6]['A']['value']);
    }

    public function test_export_requires_one_valid_indicator_and_a_period(): void
    {
        // No "all indicators" report, and every filter is checked on the server.
        foreach ([
            [['indicator_id' => null], 'indicator_id'],
            [['indicator_id' => 'semua'], 'indicator_id'],
            [['indicator_id' => 999999], 'indicator_id'],
            [['kategori' => 'imp-rs'], 'indicator_id'], // an INM indicator under IMP RS
            [['kategori' => 'lainnya'], 'kategori'],
            [['start_date' => null], 'start_date'],
            [['end_date' => null], 'end_date'],
            [['start_date' => '2026-12-01', 'end_date' => '2026-11-01'], 'end_date'],
            [['start_date' => '01-11-2026'], 'start_date'],
            [['unit_id' => 999999], 'unit_id'],
        ] as [$query, $error]) {
            foreach (['excel', 'pdf'] as $format) {
                $this->export($query, format: $format)->assertRedirect()->assertSessionHasErrors($error);
            }
        }

        // The indicator's own category is accepted.
        $this->export(['kategori' => 'inm'])->assertOk();
        $this->export(['kategori' => 'imp-unit', 'indicator_id' => $this->impUnit->id])->assertOk();

        // PIC Unit cannot export at all; PIC Mutu and Admin can.
        $this->export([], $this->picUnit)->assertForbidden();
        $this->export([], $this->picUnit, 'pdf')->assertForbidden();
        $this->export([], User::factory()->admin()->create())->assertOk();
    }

    public function test_sheet_layout_is_formatted_for_reading_and_filtering(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-10');
        $this->submitIdentification($this->ns3a, '2026-11-11');

        $bytes = $this->export()->streamedContent();
        $sheet = $this->xlsxSheetXml($bytes, 'Kepatuhan Identifikasi Pasien');
        $rows = $this->xlsxSheets($bytes)['Kepatuhan Identifikasi Pasien'];

        // Bold, coloured header with borders on every heading cell.
        foreach ($rows[self::HEADER] as $cell) {
            $this->assertSame(XlsxWorkbook::STYLES['header'], $cell['style']);
        }
        $styles = $this->xlsxParts($bytes)['xl/styles.xml'];
        $this->assertStringContainsString('<font><b/><sz val="11"/><color rgb="FFFFFFFF"/>', $styles);
        $this->assertStringContainsString('<fgColor rgb="FF172A46"/>', $styles);
        $this->assertStringContainsString('<left style="thin">', $styles);

        // Frozen header, auto filter over the data, widths for every column.
        $this->assertSame('frozen', (string) $sheet->sheetViews->sheetView->pane['state']);
        $this->assertSame('A6', (string) $sheet->sheetViews->sheetView->pane['topLeftCell']);
        $this->assertSame('A5:K7', (string) $sheet->autoFilter['ref']);
        $this->assertCount(11, $sheet->cols->col);
        foreach ($sheet->cols->col as $col) {
            $this->assertGreaterThanOrEqual(6, (float) $col['width']);
            $this->assertLessThanOrEqual(60, (float) $col['width']);
        }
        $definedNames = (new \SimpleXMLElement($this->xlsxParts($bytes)['xl/workbook.xml']))->definedNames->definedName;
        $filterNames = array_values(array_filter(iterator_to_array($definedNames, false), fn ($name) => (string) $name['name'] === '_xlnm._FilterDatabase'));
        $this->assertSame(["'Kepatuhan Identifikasi Pasien'!\$A\$5:\$K\$7"], array_map('strval', $filterNames));

        // Title block: indicator, category, period and unit on their own rows.
        $this->assertSame('Kepatuhan Identifikasi Pasien', $rows[1]['A']['value']);
        $this->assertStringStartsWith('Kategori: Indikator Nasional Mutu', $rows[2]['A']['value']);
        $this->assertSame('Periode: 01-01-2026 s.d. 31-12-2026     Unit: Semua unit', $rows[3]['A']['value']);

        // Alternating rows: the second observation sits on the shaded band.
        $this->assertSame(XlsxWorkbook::STYLES['date'], $rows[6]['B']['style']);
        $this->assertSame(XlsxWorkbook::STYLES['date_alt'], $rows[7]['B']['style']);
        $this->assertSame(XlsxWorkbook::STYLES['number_alt'], $rows[7]['A']['style']);
        $this->assertStringContainsString('<fgColor rgb="FFF4F7FB"/>', $styles);
        $this->assertStringContainsString('<alignment horizontal="center" vertical="center" wrapText="1"/>', $styles);

        // Header row tall enough for wrapped labels; print setup for paper.
        $header = $sheet->xpath('//*[local-name()="row"][@r="5"]')[0];
        $this->assertSame('1', (string) $header['customHeight']);
        $this->assertGreaterThan(15, (float) $header['ht']);
        $this->assertSame('0', (string) $sheet->sheetViews->sheetView['showGridLines']);
        $this->assertSame('landscape', (string) $sheet->pageSetup['orientation']);
        $this->assertStringContainsString('Halaman &P dari &N', (string) $sheet->headerFooter->oddFooter);
        $printTitles = array_values(array_filter(
            iterator_to_array($definedNames, false),
            fn ($name) => (string) $name['name'] === '_xlnm.Print_Titles',
        ));
        $this->assertSame("'Kepatuhan Identifikasi Pasien'!\$1:\$5", (string) $printTitles[0]);
    }

    public function test_sheet_name_respects_excel_limits(): void
    {
        $indicator = $this->indicator('IMP_RS', 'Indikator Mutu Pelayanan Rumah Sakit', 'Kepatuhan Visite Dokter Spesialis 1x24 Jam: Rawat Inap [A]');
        $version = $this->version($indicator, 1, IndicatorFormVersion::STATUS_PUBLISHED, ['x' => ['type' => 'text', 'label' => 'X']]);
        $this->observe($indicator, $version, $this->ns3a, '2026-11-10', ['x' => 'isi']);

        $sheets = $this->workbook(['indicator_id' => $indicator->id]);

        $this->assertSame(['Ringkasan', 'Kepatuhan Visite Dokter Spesial'], array_keys($sheets));
        $this->assertLessThanOrEqual(31, mb_strlen(array_keys($sheets)[1]));

        // The full name stays in the sheet title and the summary.
        $this->assertSame($indicator->name, $sheets['Kepatuhan Visite Dokter Spesial'][1]['A']['value']);
        $this->assertSame($indicator->name, $sheets['Ringkasan'][5]['B']['value']);
    }

    public function test_pdf_has_one_table_for_the_chosen_indicator_without_a_detail_column(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-10');
        $this->submitIdentification($this->igd, '2026-11-12', ['patient_name' => 'Pasien IGD']);
        $this->observe($this->impUnit, $this->impUnit->formVersions()->first(), $this->igd, '2026-11-11', ['patuh' => '1', 'momen' => '2']);

        $data = null;
        View::composer('observations.pdf', function ($view) use (&$data) {
            $data = $view->getData();
        });

        $response = $this->export(format: 'pdf')->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/filename=laporan-kepatuhan-identifikasi-pasien-\d{8}-\d{6}\.pdf/', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Every unit, one indicator.
        $this->assertSame([2, 2], [$data['total'], $data['units']]);
        $html = view('observations.pdf', $data)->render();
        $this->assertStringContainsString('Kepatuhan Identifikasi Pasien', $html);
        $this->assertStringContainsString('<th>Nama Pasien</th>', $html);
        $this->assertStringContainsString('<td>Norbayah</td>', $html);
        $this->assertStringContainsString('<td>Pasien IGD</td>', $html);
        $this->assertStringContainsString('Unit melapor: <strong>2</strong>', $html);
        $this->assertStringNotContainsString('Kepatuhan Cuci Tangan Unit', $html);
        $this->assertStringNotContainsString('<th>Detail</th>', $html);

        // The IMP Unit indicator on its own.
        $this->export(['indicator_id' => $this->impUnit->id], format: 'pdf')->assertOk();
        $html = view('observations.pdf', $data)->render();
        $this->assertStringContainsString('<th>Momen</th>', $html);
        $this->assertStringContainsString('<td>Sesudah kontak pasien</td>', $html);
        $this->assertStringNotContainsString('Norbayah', $html);
    }

    public function test_exporting_changes_no_data(): void
    {
        $this->submitIdentification($this->ns3a, '2026-11-10');
        $this->observe($this->impRs, $this->impRs->formVersions()->first(), $this->ns3a, '2026-11-10', ['nama_petugas' => 'Rina', 'dokumen' => ['cppt']]);

        $snapshot = fn () => [
            DB::table('observations')->orderBy('id')->get()->toArray(),
            DB::table('observation_values')->orderBy('id')->get()->toArray(),
            DB::table('indicator_fields')->orderBy('id')->get()->toArray(),
            DB::table('indicator_form_versions')->orderBy('id')->get()->toArray(),
        ];
        $before = $snapshot();

        $this->export()->assertOk()->streamedContent();
        $this->export(['indicator_id' => $this->impRs->id])->assertOk()->streamedContent();
        $this->export(format: 'pdf')->assertOk();

        $this->assertEquals($before, $snapshot());
    }
}
