<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use App\Support\Xlsx\XlsxWorkbook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

class UnitIndicatorConfigurationTest extends TestCase
{
    use CreatesInmIndicators, ReadsXlsx, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $indicators;

    /** Unit configured with 2 INM indicators. */
    private Unit $twoUnit;

    /** Unit configured with all 4 INM indicators. */
    private Unit $fourUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indicators = $this->createInmIndicators();
        $this->twoUnit = Unit::create(['code' => 'NS2A', 'name' => 'Nurse Station 2A', 'is_active' => true]);
        $this->fourUnit = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);

        $this->assignIndicators($this->twoUnit, $this->indicators, [ObservationForms::IDENTIFICATION, ObservationForms::FALL_PREVENTION]);
        $this->assignIndicators($this->fourUnit, $this->indicators);
    }

    // --- 2 vs 4 indicators -------------------------------------------------

    public function test_input_page_lists_only_the_units_assigned_indicators(): void
    {
        $two = $this->actingAs(User::factory()->picUnit($this->twoUnit)->create())
            ->get(route('observations.create'))
            ->assertOk();
        $this->assertSame(
            [$this->indicators[ObservationForms::IDENTIFICATION]->id, $this->indicators[ObservationForms::FALL_PREVENTION]->id],
            $two->viewData('indicators')->pluck('id')->all()
        );
        $two->assertSee('Kepatuhan Identifikasi Pasien')
            ->assertSee('Kepatuhan Upaya Pencegahan Risiko Pasien Jatuh')
            ->assertDontSee('Kepatuhan Penggunaan Alat Pelindung Diri')
            ->assertDontSee('Kepatuhan Visite Dokter Spesialis');

        $four = $this->actingAs(User::factory()->picUnit($this->fourUnit)->create())
            ->get(route('observations.create'))
            ->assertOk();
        $this->assertCount(4, $four->viewData('indicators'));
    }

    public function test_unit_without_configuration_gets_no_indicators(): void
    {
        $empty = Unit::create(['code' => 'RAD', 'name' => 'Radiologi', 'is_active' => true]);

        $this->actingAs(User::factory()->picUnit($empty)->create())
            ->get(route('observations.create'))
            ->assertOk()
            ->assertViewHas('indicators', fn ($indicators) => $indicators->isEmpty())
            ->assertSee('Belum ada indikator yang ditetapkan untuk unit Anda.');
    }

    public function test_dashboard_does_not_show_unassigned_indicators_as_zero(): void
    {
        $user = User::factory()->picUnit($this->twoUnit)->create();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $listed = $response->viewData('indicators');
        $this->assertCount(2, $listed);
        $this->assertTrue($listed->every(fn ($indicator) => $indicator->assigned && $indicator->total === 0));
        $response->assertDontSee('Kepatuhan Penggunaan Alat Pelindung Diri');
    }

    public function test_admin_dashboard_lists_each_units_own_indicators(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $rows = $response->viewData('unitRows')->keyBy(fn ($row) => $row['unit']->code);
        // Units are listed even before any observation exists, each with
        // only the indicators mapped to it and without being marked as reported.
        $this->assertEqualsCanonicalizing(['NS2A', 'IGD'], $rows->keys()->all());
        $this->assertSame([2, 0, false], [$rows['NS2A']['indicators'], $rows['NS2A']['total'], $rows['NS2A']['reported']]);
        $this->assertSame(4, $rows['IGD']['indicators']);
        $response->assertSee('Belum melapor')->assertDontSee('Sudah melapor');

        // With a unit filter, only that unit's configured indicators are listed.
        $filtered = $this->actingAs($admin)->get(route('dashboard', ['unit_id' => $this->twoUnit->id]));
        $this->assertCount(2, $filtered->viewData('indicators'));
    }

    // --- cross-unit access -------------------------------------------------

    public function test_pic_unit_cannot_submit_an_indicator_not_assigned_to_their_unit(): void
    {
        $user = User::factory()->picUnit($this->twoUnit)->create();
        $ppe = $this->indicators[ObservationForms::PPE];

        $this->actingAs($user)->get(route('observations.create.indicator', $ppe))->assertForbidden();

        // Even when claiming a unit that has PPE configured.
        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $ppe->id,
            'unit_id' => $this->fourUnit->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas',
            'procedure' => 'Tindakan',
            'mask' => '1', 'gloves' => '1', 'gown' => '1', 'cap' => '1', 'closed_footwear' => '1',
        ])->assertSessionHasErrors(['indicator_id' => 'Indikator tidak aktif, belum memiliki formulir terbit, atau tidak ditetapkan untuk unit Anda.']);

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_pic_unit_can_submit_an_assigned_indicator_for_own_unit_only(): void
    {
        $user = User::factory()->picUnit($this->twoUnit)->create();

        $this->actingAs($user)->post(route('observations.store'), [
            'indicator_id' => $this->indicators[ObservationForms::FALL_PREVENTION]->id,
            'unit_id' => $this->fourUnit->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas',
            'patient_name' => 'Pasien',
            'medical_record_number' => '123',
            'initial_screening' => '1', 'follow_up_screening' => '1', 'education' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->twoUnit->id, Observation::sole()->unit_id);
    }

    public function test_assignment_of_another_unit_does_not_grant_access(): void
    {
        // PPE is configured for IGD only; an NS2A account still cannot use it.
        $user = User::factory()->picUnit($this->twoUnit)->create();

        $this->actingAs($user)
            ->get(route('observations.index'))
            ->assertViewHas('indicators', fn ($indicators) => ! $indicators->contains('id', $this->indicators[ObservationForms::PPE]->id));
    }

    public function test_admin_and_pic_mutu_still_see_all_units(): void
    {
        $this->observation($this->twoUnit, ObservationForms::IDENTIFICATION, 'Pasien NS2A');
        $this->observation($this->fourUnit, ObservationForms::PPE, 'Pasien IGD');

        foreach ([User::factory()->admin()->create(), User::factory()->picMutu()->create()] as $user) {
            // One indicator per report; Semua Unit by default.
            $this->actingAs($user)->get(route('observations.index', ['indicator_id' => $this->indicators[ObservationForms::IDENTIFICATION]->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
                ->assertSee('Pasien NS2A');
            // The PPE form has no patient field: the row is there, counted with its unit.
            $this->actingAs($user)->get(route('observations.index', ['indicator_id' => $this->indicators[ObservationForms::PPE]->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
                ->assertSee('Instalasi Gawat Darurat')
                ->assertViewHas('result', fn ($result) => $result['total'] === 1 && $result['units'] === 1);
        }
    }

    // --- configuration ------------------------------------------------------

    /**
     * Replace a unit's INM mappings through "Mapping Indikator & Unit", the
     * only place they are managed: each indicator's unit list is saved with
     * or without this unit.
     *
     * @param  list<string>  $formTypes
     */
    private function configure(User $admin, Unit $unit, array $formTypes): void
    {
        foreach ($this->indicators as $formType => $indicator) {
            $unitIds = $indicator->assignedUnits()->pluck('units.id')
                ->reject(fn ($id) => $id === $unit->id)
                ->when(in_array($formType, $formTypes, true), fn ($ids) => $ids->push($unit->id))
                ->values()->all();

            $this->actingAs($admin)
                ->put(route('indicator-unit-mappings.update', $indicator), ['unit_ids' => $unitIds])
                ->assertSessionHasNoErrors();
        }
    }

    public function test_admin_can_configure_unit_indicators(): void
    {
        $admin = User::factory()->admin()->create();

        $this->configure($admin, $this->twoUnit, [ObservationForms::PPE, ObservationForms::SPECIALIST_VISIT]);

        $this->assertEqualsCanonicalizing(
            [$this->indicators[ObservationForms::PPE]->id, $this->indicators[ObservationForms::SPECIALIST_VISIT]->id],
            $this->twoUnit->assignedIndicators()->pluck('indicators.id')->all()
        );
        // Other units are untouched.
        $this->assertCount(4, $this->fourUnit->assignedIndicators()->get());

        // The unit's PIC can now record exactly those indicators.
        $picUnit = User::factory()->picUnit($this->twoUnit)->create();
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->indicators[ObservationForms::PPE]))->assertOk();
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->indicators[ObservationForms::IDENTIFICATION]))->assertForbidden();

        // Removing every mapping leaves the unit without assignments.
        $this->configure($admin, $this->twoUnit, []);
        $this->assertCount(0, $this->twoUnit->assignedIndicators()->get());
    }

    public function test_per_unit_inm_configuration_is_gone_from_unit_management(): void
    {
        $admin = User::factory()->admin()->create();

        // The old page and its endpoint no longer exist.
        $this->actingAs($admin)->get("/units/{$this->twoUnit->id}/indicators")->assertNotFound();
        $this->actingAs($admin)->put("/units/{$this->twoUnit->id}/indicators", [
            'indicator_ids' => [$this->indicators[ObservationForms::PPE]->id],
        ])->assertNotFound();

        // The unit list has no INM configuration left on it.
        $this->actingAs($admin)->get(route('units.index'))
            ->assertOk()
            ->assertSee('Nurse Station 2A')
            ->assertDontSee('Atur Indikator INM')
            ->assertDontSee('Indikator INM')
            ->assertDontSee('Identifikasi Pasien');

        // Nothing was changed by the attempts above.
        $this->assertCount(2, $this->twoUnit->assignedIndicators()->get());
    }

    public function test_unit_management_still_creates_edits_and_toggles_units(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('units.store'), ['code' => 'lab', 'name' => 'Laboratorium'])
            ->assertSessionHasNoErrors()->assertRedirect(route('units.index'));
        $unit = Unit::where('code', 'LAB')->sole();
        $this->assertTrue($unit->is_active);

        $this->actingAs($admin)->put(route('units.update', $unit), ['code' => 'labor', 'name' => 'Laboratorium Klinik'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['LABOR', 'Laboratorium Klinik'], [$unit->fresh()->code, $unit->fresh()->name]);

        $this->actingAs($admin)->patch(route('units.toggle-status', $unit))->assertRedirect(route('units.index'));
        $this->assertFalse($unit->fresh()->is_active);

        // Editing or deactivating a unit keeps its indicator mappings.
        $this->actingAs($admin)->put(route('units.update', $this->twoUnit), ['code' => 'NS2A', 'name' => 'Perawat Lantai 2 Gedung A']);
        $this->actingAs($admin)->patch(route('units.toggle-status', $this->twoUnit));
        $this->assertCount(2, $this->twoUnit->assignedIndicators()->get());

        $this->actingAs($admin)->get(route('units.index'))
            ->assertOk()
            ->assertSee('Laboratorium Klinik')
            ->assertSee('Tidak Aktif')
            ->assertSee(route('units.toggle-status', $unit), false)
            ->assertSee(route('units.destroy', $unit), false);

        $this->actingAs($admin)->patch(route('units.toggle-status', $unit));
        $this->assertTrue($unit->fresh()->is_active);

        $this->actingAs($admin)->delete(route('units.destroy', $unit))->assertSessionHas('success');
        $this->assertModelMissing($unit);
    }

    public function test_configuration_is_admin_only(): void
    {
        $indicator = $this->indicators[ObservationForms::PPE];

        foreach ([User::factory()->picMutu()->create(), User::factory()->picUnit($this->twoUnit)->create()] as $user) {
            $this->actingAs($user)->get(route('indicator-unit-mappings.edit', $indicator))->assertForbidden();
            $this->actingAs($user)->put(route('indicator-unit-mappings.update', $indicator), [
                'unit_ids' => [$this->twoUnit->id, $this->fourUnit->id],
            ])->assertForbidden();
        }

        $this->assertCount(2, $this->twoUnit->assignedIndicators()->get());
    }

    // --- historical data ---------------------------------------------------

    public function test_removing_an_assignment_keeps_historical_observations_visible_and_exportable(): void
    {
        $picUnit = User::factory()->picUnit($this->twoUnit)->create();
        $admin = User::factory()->admin()->create();
        $fall = $this->indicators[ObservationForms::FALL_PREVENTION];
        $observation = $this->observation($this->twoUnit, ObservationForms::FALL_PREVENTION, 'Pasien Historis');

        $this->configure($admin, $this->twoUnit, [ObservationForms::IDENTIFICATION]);

        // The observation itself is untouched.
        $this->assertModelExists($observation);
        $this->assertSame($fall->id, $observation->fresh()->indicator_id);

        // PIC Unit: still in history, detail and the indicator filter, but no new input.
        $this->actingAs($picUnit)->get(route('observations.index'))
            ->assertViewHas('indicators', fn ($indicators) => $indicators->contains('id', $fall->id));
        $this->actingAs($picUnit)->get(route('observations.index', ['indicator_id' => $fall->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertSee('Pasien Historis');
        $this->actingAs($picUnit)->get(route('observations.show', $observation))->assertOk();
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $fall))->assertForbidden();

        // Dashboard keeps counting it, flagged as historical.
        $dashboard = $this->actingAs($picUnit)->get(route('dashboard'));
        $listed = $dashboard->viewData('indicators')->keyBy('id');
        $this->assertSame(1, $listed[$fall->id]->total);
        $this->assertFalse($listed[$fall->id]->assigned);
        $dashboard->assertSee('Data historis · tidak lagi ditetapkan');

        // Admin export still includes it.
        $sheets = $this->xlsxSheets($this->actingAs($admin)
            ->get(route('observations.export', ['format' => 'excel', 'indicator_id' => $fall->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
            ->streamedContent());
        $this->assertContains('Pasien Historis', $this->xlsxValues($sheets[XlsxWorkbook::sheetName($fall->name)][6]));
    }

    public function test_existing_observations_survive_full_reconfiguration(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (ObservationForms::INM_INDICATORS as $formType) {
            $this->observation($this->fourUnit, $formType, "Pasien {$formType}");
        }

        $this->configure($admin, $this->fourUnit, []);

        $this->assertDatabaseCount('observations', 4);
        $this->assertCount(0, $this->fourUnit->assignedIndicators()->get());
        foreach (ObservationForms::INM_INDICATORS as $formType) {
            $this->actingAs($admin)->get(route('observations.index', ['indicator_id' => $this->indicators[$formType]->id, 'unit_id' => $this->fourUnit->id, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->toDateString()]))
                ->assertViewHas('result', fn ($result) => $result['total'] === 1);
        }
    }

    private function observation(Unit $unit, string $formType, string $name): Observation
    {
        $author = User::factory()->picUnit($unit)->create();
        $isVisit = $formType === ObservationForms::SPECIALIST_VISIT;

        return Observation::create([
            'indicator_id' => $this->indicators[$formType]->id,
            'unit_id' => $unit->id,
            'user_id' => $author->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => $isVisit ? null : 'Petugas',
            'patient_name' => $isVisit ? null : $name,
            'data' => $isVisit ? ['doctor_name' => $name, 'patients_treated' => 1, 'patients_visited_on_time' => 1] : null,
        ]);
    }
}
