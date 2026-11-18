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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

/**
 * The observation report page: category, exactly one indicator, period and
 * unit, with every form answer in its own column.
 */
class ObservationReportPageTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private const PERIOD = ['start_date' => '2026-11-01', 'end_date' => '2026-11-30'];

    /** @var array<string, Indicator> */
    private array $inm;

    private Unit $ns3a;

    private Unit $igd;

    private User $admin;

    private User $picMutu;

    private User $picUnit;

    private Indicator $impRs;

    private Indicator $impUnit;

    private Indicator $draftOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inm = $this->createInmIndicators();
        $this->ns3a = Unit::create(['code' => 'NS3A', 'name' => 'Rawat Inap NS3A', 'is_active' => true]);
        $this->igd = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->assignIndicators($this->ns3a, $this->inm);
        $this->assignIndicators($this->igd, $this->inm);

        $this->admin = User::factory()->admin()->create(['name' => 'Admin Mutu']);
        $this->picMutu = User::factory()->picMutu()->create();
        $this->picUnit = User::factory()->picUnit($this->ns3a)->create(['name' => 'Perawat NS3A']);

        $this->impRs = $this->indicator('IMP_RS', 'imp-rs', 'Indikator Mutu Pelayanan Rumah Sakit', 'Kepatuhan Surgical Safety Checklist');
        $this->form($this->impRs, [
            'nama_pasien' => ['type' => 'text', 'label' => 'Nama Pasien'],
            'ruang' => ['type' => 'select', 'label' => 'Ruangan', 'options' => ['ok' => 'Ruang OK', 'icu' => 'ICU']],
            'nama_petugas' => ['type' => 'text', 'label' => 'Nama Petugas'],
            'sign_in' => ['type' => 'yes_no', 'label' => 'Sign In', 'section' => 'Checklist'],
            'catatan' => ['type' => 'textarea', 'label' => 'Catatan'],
        ]);
        $this->impRs->assignedUnits()->attach([$this->ns3a->id, $this->igd->id]);

        $this->impUnit = $this->indicator('IMP_UNIT', 'imp-unit', 'Indikator Mutu Pelayanan Unit', 'Waktu Tanggap Perawat');
        $this->form($this->impUnit, ['menit' => ['type' => 'number', 'label' => 'Waktu Tanggap (menit)']]);
        $this->impUnit->assignedUnits()->attach($this->ns3a->id);

        // A form that was never published cannot be reported on.
        $this->draftOnly = $this->indicator('IMP_RS', 'imp-rs', 'Indikator Mutu Pelayanan Rumah Sakit', 'Indikator Draf Saja');
        $this->form($this->draftOnly, ['x' => ['type' => 'text', 'label' => 'X']], IndicatorFormVersion::STATUS_DRAFT);
    }

    // --- helpers -------------------------------------------------------------------------

    private function indicator(string $code, string $slug, string $categoryName, string $name): Indicator
    {
        $categoryId = DB::table('indicator_categories')->where('code', $code)->value('id')
            ?? DB::table('indicator_categories')->insertGetId(['code' => $code, 'slug' => $slug, 'name' => $categoryName, 'created_at' => now(), 'updated_at' => now()]);

        return Indicator::create(['indicator_category_id' => $categoryId, 'name' => $name, 'is_active' => true]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    private function form(Indicator $indicator, array $fields, string $status = IndicatorFormVersion::STATUS_PUBLISHED): IndicatorFormVersion
    {
        $version = IndicatorFormVersion::create(['indicator_id' => $indicator->id, 'version' => 1, 'status' => $status]);

        foreach (array_keys($fields) as $order => $key) {
            $spec = $fields[$key];
            $field = IndicatorField::create([
                'indicator_form_version_id' => $version->id, 'key' => $key, 'label' => $spec['label'], 'type' => $spec['type'],
                'section' => $spec['section'] ?? null, 'is_required' => false, 'is_active' => true, 'sort_order' => $order,
            ]);
            foreach (array_keys($spec['options'] ?? []) as $i => $value) {
                $field->options()->create(['value' => (string) $value, 'label' => $spec['options'][$value], 'sort_order' => $i]);
            }
        }

        return $version->load('fields.options');
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    private function observe(Indicator $indicator, Unit $unit, string $date, array $answers): Observation
    {
        $version = $indicator->formVersions()->with('fields.options')->first();
        $observation = Observation::create([
            'indicator_id' => $indicator->id, 'indicator_form_version_id' => $version->id,
            'unit_id' => $unit->id, 'user_id' => $this->picUnit->id, 'observation_date' => $date,
        ]);
        ObservationAnswers::sync($observation, $version, $answers);

        return $observation;
    }

    private function report(User $user, array $query = [])
    {
        return $this->actingAs($user)->get(route('observations.index', $query));
    }

    // --- categories ----------------------------------------------------------------------

    public function test_three_categories_each_list_only_their_own_indicators(): void
    {
        $response = $this->report($this->admin)->assertOk()
            ->assertSee('Laporan Observasi Mutu')
            ->assertSeeInOrder(['data-report-category="inm"', 'data-report-category="imp-rs"', 'data-report-category="imp-unit"'], false);

        // INM by default; nothing is reported until one indicator is chosen.
        $this->assertSame('inm', $response->viewData('report')->category);
        $this->assertEqualsCanonicalizing(collect($this->inm)->pluck('id')->all(), $response->viewData('indicators')->pluck('id')->all());
        $this->assertSame(['inm' => 4, 'imp-rs' => 1, 'imp-unit' => 1], $response->viewData('categories')->map->count->all());
        $this->assertNull($response->viewData('result'));
        $response->assertSee('Pilih satu indikator untuk menampilkan laporan.')
            ->assertDontSee('data-report-table', false)
            ->assertDontSee('Semua indikator');

        $this->report($this->admin, ['kategori' => 'imp-rs'])
            ->assertViewHas('indicators', fn ($list) => $list->pluck('id')->all() === [$this->impRs->id]);
        $this->report($this->admin, ['kategori' => 'imp-unit'])
            ->assertViewHas('indicators', fn ($list) => $list->pluck('id')->all() === [$this->impUnit->id]);

        // An unknown category is refused.
        $this->report($this->admin, ['kategori' => 'semua'])->assertSessionHasErrors('kategori');
    }

    public function test_every_category_reports_its_indicator_with_one_column_per_answer(): void
    {
        $this->observe($this->inm[ObservationForms::IDENTIFICATION], $this->ns3a, '2026-11-10', [
            'officer_name' => 'Taufik', 'patient_name' => 'Norbayah', 'medication_compliant' => '1', 'procedure_compliant' => '0',
            'patient_name_verified' => '1', 'birth_date_verified' => '1',
        ]);
        $this->observe($this->impRs, $this->igd, '2026-11-11', ['nama_pasien' => 'Tn. Budi', 'ruang' => 'icu', 'nama_petugas' => 'Rina', 'sign_in' => '1', 'catatan' => 'Lengkap']);
        $this->observe($this->impUnit, $this->ns3a, '2026-11-12', ['menit' => '7']);

        // INM
        $inm = $this->report($this->picMutu, ['kategori' => 'inm', 'indicator_id' => $this->inm[ObservationForms::IDENTIFICATION]->id, ...self::PERIOD])->assertOk();
        $inm->assertSee('Kepatuhan Identifikasi Pasien')
            ->assertSee('class="table-base table-report text-sm"', false)
            ->assertSeeInOrder(['<th class="w-14 whitespace-nowrap tabular-nums">No</th>', 'Tanggal Observasi', 'Unit', 'Diinput Oleh', 'Nama Petugas yang Diobservasi', 'Nama Pasien'], false)
            ->assertSee('Norbayah')->assertSee('Taufik')->assertSee('10-11-2026')->assertSee('Perawat NS3A')
            ->assertDontSee('<th>Ringkasan</th>', false)
            ->assertDontSee('>Detail</th>', false);

        // IMP RS: every field a column, the officer's name right after "Diinput Oleh", choices as labelled.
        $rs = $this->report($this->picMutu, ['kategori' => 'imp-rs', 'indicator_id' => $this->impRs->id, ...self::PERIOD])->assertOk();
        $rs->assertSeeInOrder(['Diinput Oleh', 'Nama Petugas', 'Nama Pasien', 'Ruangan', 'Checklist — Sign In', 'Catatan'])
            ->assertSeeInOrder(['Instalasi Gawat Darurat', 'Rina', 'Tn. Budi', 'ICU', 'Ya', 'Lengkap'])
            ->assertDontSee('Versi Formulir')
            ->assertDontSee('Versi 1')
            ->assertDontSee('Norbayah');

        // IMP Unit
        $this->report($this->picMutu, ['kategori' => 'imp-unit', 'indicator_id' => $this->impUnit->id, ...self::PERIOD])->assertOk()
            ->assertSee('Waktu Tanggap (menit)')
            ->assertSee('Waktu Tanggap Perawat')
            ->assertViewHas('result', fn ($result) => $result['total'] === 1)
            ->assertDontSee('Tn. Budi');
    }

    // --- indicator filter -------------------------------------------------------------------

    public function test_indicator_must_be_one_reportable_indicator_of_the_category(): void
    {
        foreach ([
            ['kategori' => 'imp-rs', 'indicator_id' => $this->inm[ObservationForms::PPE]->id], // another category
            ['kategori' => 'imp-rs', 'indicator_id' => $this->draftOnly->id],                  // never published
            ['kategori' => 'inm', 'indicator_id' => 999999],
            ['kategori' => 'inm', 'indicator_id' => 'semua'],
        ] as $query) {
            $this->report($this->admin, $query)->assertRedirect()->assertSessionHasErrors('indicator_id');
        }

        // Older links name only the indicator: its own category is used.
        $this->report($this->admin, ['indicator_id' => $this->impRs->id])->assertOk()
            ->assertViewHas('report', fn ($report) => $report->category === 'imp-rs' && $report->indicator->is($this->impRs));
    }

    public function test_counts_and_rows_cover_only_the_chosen_indicator_and_period(): void
    {
        $this->observe($this->impRs, $this->ns3a, '2026-11-02', ['nama_pasien' => 'Pasien Satu']);
        $this->observe($this->impRs, $this->ns3a, '2026-11-20', ['nama_pasien' => 'Pasien Dua']);
        $this->observe($this->impRs, $this->igd, '2026-11-21', ['nama_pasien' => 'Pasien IGD']);
        $this->observe($this->impRs, $this->igd, '2026-10-30', ['nama_pasien' => 'Pasien Oktober']);
        $this->observe($this->impUnit, $this->ns3a, '2026-11-05', ['menit' => '3']);

        $this->report($this->admin, ['kategori' => 'imp-rs', 'indicator_id' => $this->impRs->id, ...self::PERIOD])
            ->assertViewHas('result', fn ($result) => $result['total'] === 3 && $result['units'] === 2)
            ->assertSee('data-report-total>3</dd>', false)
            ->assertSee('data-report-units>2</dd>', false)
            ->assertSeeInOrder(['Pasien Satu', 'Pasien Dua', 'Pasien IGD'])
            ->assertDontSee('Pasien Oktober');

        // The period: start and end are both checked.
        $this->report($this->admin, ['indicator_id' => $this->impRs->id, 'start_date' => '2026-11-30', 'end_date' => '2026-11-01'])
            ->assertSessionHasErrors('end_date');
        $this->report($this->admin, ['indicator_id' => $this->impRs->id, 'start_date' => 'kemarin'])
            ->assertSessionHasErrors('start_date');
    }

    // --- unit filter -------------------------------------------------------------------------

    public function test_all_units_combine_one_indicator_and_a_unit_narrows_it(): void
    {
        $this->observe($this->impRs, $this->ns3a, '2026-11-02', ['nama_pasien' => 'Pasien NS3A']);
        $this->observe($this->impRs, $this->igd, '2026-11-03', ['nama_pasien' => 'Pasien IGD']);
        $this->observe($this->impUnit, $this->igd, '2026-11-03', ['menit' => '9']);
        $query = ['kategori' => 'imp-rs', 'indicator_id' => $this->impRs->id, ...self::PERIOD];

        foreach ([$this->admin, $this->picMutu] as $user) {
            $this->report($user, [...$query, 'unit_id' => 'semua'])
                ->assertSee('Pasien NS3A')->assertSee('Pasien IGD')
                ->assertViewHas('result', fn ($result) => $result['total'] === 2 && $result['units'] === 2);

            $this->report($user, [...$query, 'unit_id' => $this->igd->id])
                ->assertSee('Pasien IGD')->assertDontSee('Pasien NS3A')
                ->assertViewHas('result', fn ($result) => $result['total'] === 1 && $result['units'] === 1);
        }

        $this->report($this->admin, [...$query, 'unit_id' => 999999])->assertSessionHasErrors('unit_id');
    }

    // --- access ------------------------------------------------------------------------------

    public function test_pic_unit_sees_only_their_own_unit(): void
    {
        $this->observe($this->impRs, $this->ns3a, '2026-11-02', ['nama_pasien' => 'Pasien NS3A']);
        $other = $this->observe($this->impRs, $this->igd, '2026-11-03', ['nama_pasien' => 'Pasien IGD']);
        $query = ['kategori' => 'imp-rs', 'indicator_id' => $this->impRs->id, ...self::PERIOD];

        // Pinned to the own unit, also when asking for every unit.
        foreach ([[], ['unit_id' => 'semua'], ['unit_id' => $this->ns3a->id]] as $unit) {
            $this->report($this->picUnit, [...$query, ...$unit])->assertOk()
                ->assertSee('Pasien NS3A')->assertDontSee('Pasien IGD')
                ->assertViewHas('result', fn ($result) => $result['total'] === 1 && $result['units'] === 1)
                ->assertViewHas('units', fn ($units) => $units->isEmpty());
        }

        // Another unit is refused, not silently answered.
        $this->report($this->picUnit, [...$query, 'unit_id' => $this->igd->id])
            ->assertSessionHasErrors(['unit_id' => 'Anda hanya dapat melihat laporan unit Anda sendiri.']);

        // Only indicators of their unit; no export; the other unit's record stays closed.
        $igdOnly = $this->indicator('IMP_RS', 'imp-rs', 'Indikator Mutu Pelayanan Rumah Sakit', 'Khusus IGD');
        $this->form($igdOnly, ['y' => ['type' => 'text', 'label' => 'Y']]);
        $igdOnly->assignedUnits()->attach($this->igd->id);
        $this->report($this->picUnit, ['kategori' => 'imp-rs'])
            ->assertViewHas('indicators', fn ($list) => $list->pluck('id')->all() === [$this->impRs->id]);
        $this->report($this->picUnit, ['kategori' => 'imp-rs', 'indicator_id' => $igdOnly->id])->assertSessionHasErrors('indicator_id');
        $this->report($this->picUnit, $query)->assertDontSee(route('observations.export', 'excel'));
        $this->actingAs($this->picUnit)->get(route('observations.show', $other))->assertForbidden();
    }

    public function test_actions_follow_the_existing_rules(): void
    {
        $observation = $this->observe($this->impRs, $this->ns3a, '2026-11-02', ['nama_pasien' => 'Pasien NS3A']);
        $query = ['kategori' => 'imp-rs', 'indicator_id' => $this->impRs->id, ...self::PERIOD];
        $export = fn (string $format) => route('observations.export', ['format' => $format, ...$query]);

        // PIC Mutu reads and exports, never edits.
        $this->report($this->picMutu, $query)
            ->assertSee(route('observations.show', $observation))
            ->assertDontSee(route('observations.edit', $observation))
            ->assertSee($export('excel'))
            ->assertSee($export('pdf'));

        // Admin: every unit, edit and export.
        $this->report($this->admin, $query)
            ->assertSee(route('observations.edit', $observation))
            ->assertSee($export('excel'));

        // PIC Unit: edits their own unit's record, no export.
        $this->report($this->picUnit, $query)
            ->assertSee(route('observations.edit', $observation))
            ->assertDontSee($export('excel'));

        // Roles without access to observations never get the report.
        $this->actingAs(User::factory()->create(['role' => 'tamu']))->get(route('observations.index'))->assertForbidden();
    }

    public function test_pages_number_rows_continuously_and_reading_changes_no_data(): void
    {
        foreach (range(1, 27) as $day) {
            $this->observe($this->impUnit, $this->ns3a, sprintf('2026-11-%02d', $day), ['menit' => (string) $day]);
        }
        $snapshot = fn () => [DB::table('observations')->get()->toArray(), DB::table('observation_values')->get()->toArray(), DB::table('indicator_unit')->get()->toArray()];
        $before = $snapshot();

        $query = ['kategori' => 'imp-unit', 'indicator_id' => $this->impUnit->id, ...self::PERIOD];
        $this->report($this->admin, $query)->assertViewHas('result', fn ($result) => $result['total'] === 27 && $result['observations']->count() === 25);
        $this->report($this->admin, [...$query, 'page' => 2])
            ->assertViewHas('result', fn ($result) => $result['observations']->count() === 2)
            ->assertSeeInOrder(['<span class="cell-text">26</span>', '<span class="cell-text">27</span>'], false)
            // Actions centred in their own column; long answers wrap, nothing is cut off.
            ->assertSee('<th class="w-28">Aksi</th>', false)
            ->assertSee('flex items-center justify-center gap-1', false)
            ->assertDontSee('line-clamp', false);

        $this->assertEquals($before, $snapshot());
    }
}
