<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $inm;

    private Indicator $impRs;

    private Indicator $impRsUnmapped;

    private Indicator $impUnit;

    private Unit $igd;

    private Unit $icu;

    private Unit $lab;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inm = $this->createInmIndicators();
        $this->admin = User::factory()->admin()->create();

        $this->igd = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
        $this->icu = Unit::create(['code' => 'ICU', 'name' => 'Intensive Care Unit', 'is_active' => true]);
        $this->lab = Unit::create(['code' => 'LAB', 'name' => 'Laboratorium', 'is_active' => true]);

        $rs = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'Indikator Mutu Pelayanan Rumah Sakit']);
        $unit = IndicatorCategory::forceCreate(['code' => 'IMP_UNIT', 'slug' => 'imp-unit', 'name' => 'Indikator Mutu Pelayanan Unit']);

        $this->impRs = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kepatuhan SBAR', 'is_active' => true]);
        $this->impRsUnmapped = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kelengkapan CPPT', 'is_active' => true]);
        $this->impUnit = Indicator::create(['indicator_category_id' => $unit->id, 'unit_id' => $this->lab->id, 'name' => 'Waktu Tunggu Hasil Lab', 'is_active' => true]);

        // IGD: all INM + SBAR. ICU: two INM + SBAR. LAB: the IMP Unit indicator.
        $this->assignIndicators($this->igd, $this->inm);
        $this->assignIndicators($this->icu, $this->inm, [ObservationForms::IDENTIFICATION, ObservationForms::PPE]);
        $this->impRs->assignedUnits()->attach([$this->igd->id, $this->icu->id]);
        $this->impUnit->assignedUnits()->attach($this->lab->id);
    }

    private function observe(Indicator $indicator, Unit $unit, ?string $date = null, int $times = 1): void
    {
        $author = User::factory()->picUnit($unit)->create();

        for ($i = 0; $i < $times; $i++) {
            Observation::create([
                'indicator_id' => $indicator->id,
                'unit_id' => $unit->id,
                'user_id' => $author->id,
                'observation_date' => $date ?? now()->toDateString(),
                'officer_name' => 'Petugas',
            ]);
        }
    }

    private function dashboard(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('dashboard', $query))->assertOk();
    }

    public function test_all_three_categories_are_listed_with_units_observations_and_status(): void
    {
        $identification = $this->inm[ObservationForms::IDENTIFICATION];
        $this->observe($identification, $this->igd, times: 3);
        $this->observe($this->impRs, $this->igd, times: 2);
        $this->observe($this->impRs, $this->icu);

        $response = $this->dashboard($this->admin);
        $listed = $response->viewData('indicators')->keyBy('id');

        // Every active indicator of every category is there.
        $this->assertCount(7, $listed);
        $this->assertSame(['INM' => 4, 'IMP_RS' => 2, 'IMP_UNIT' => 1], $listed->countBy(fn ($i) => $i->category->code)->all());

        $facts = fn (Indicator $i) => [$listed[$i->id]->assigned_units_count, $listed[$i->id]->total, $listed[$i->id]->reporting_units_count];
        $this->assertSame([2, 3, 1], $facts($identification));
        $this->assertSame([2, 3, 2], $facts($this->impRs));
        $this->assertSame([0, 0, 0], $facts($this->impRsUnmapped));
        $this->assertSame([1, 0, 0], $facts($this->impUnit));

        $response
            ->assertSee('Ringkasan Indikator Mutu')
            ->assertSee('IMP RS')
            ->assertSee('IMP Unit')
            ->assertSee('Kepatuhan SBAR')
            ->assertSee('Waktu Tunggu Hasil Lab')
            ->assertSee('Sudah ada laporan')
            ->assertSee('Belum ada laporan')
            ->assertSee('Belum dipetakan ke unit')
            ->assertSee('2 dari 2 unit melapor')
            ->assertSee('1 dari 2 unit melapor')
            // No achievement percentage is invented.
            ->assertDontSee('%</', false);
    }

    public function test_summary_cards_match_the_database(): void
    {
        $this->observe($this->inm[ObservationForms::IDENTIFICATION], $this->igd, times: 3);
        $this->observe($this->impRs, $this->icu, times: 2);
        Unit::create(['code' => 'OLD', 'name' => 'Unit Lama', 'is_active' => false]);
        $this->impRsUnmapped->update(['is_active' => false]);

        $response = $this->dashboard($this->admin);

        $response->assertViewHas('total', Observation::count())
            ->assertViewHas('total', 5)
            ->assertViewHas('activeIndicators', Indicator::where('is_active', true)->count())
            ->assertViewHas('activeIndicators', 6)
            ->assertViewHas('totalUnits', 3)
            ->assertViewHas('reportingUnits', 2)
            ->assertSee('Total Observasi')
            ->assertSee('Total Indikator Aktif')
            ->assertSee('Total Unit')
            ->assertSee('Unit yang Sudah Melapor');

        // An inactive indicator without data is not listed; with data it stays, flagged.
        $this->assertFalse($response->viewData('indicators')->contains('id', $this->impRsUnmapped->id));
        $this->observe($this->impRsUnmapped, $this->lab);
        $this->dashboard($this->admin)
            ->assertViewHas('indicators', fn ($indicators) => $indicators->contains('id', $this->impRsUnmapped->id))
            ->assertSee('Data historis · indikator nonaktif');
    }

    public function test_observations_are_never_counted_twice(): void
    {
        // The same unit and indicator are linked through several mappings and
        // also through the IMP Unit "responsible unit" column.
        $this->impUnit->assignedUnits()->attach([$this->igd->id, $this->icu->id]);
        $this->observe($this->impUnit, $this->lab, times: 4);
        $this->observe($this->impRs, $this->igd, times: 2);

        $response = $this->dashboard($this->admin);
        $indicators = $response->viewData('indicators');
        $rows = $response->viewData('unitRows');

        $response->assertViewHas('total', 6);
        $this->assertSame(6, $indicators->sum('total'));
        $this->assertSame(6, $rows->sum('total'));
        $this->assertSame($indicators->count(), $indicators->unique('id')->count());
        $this->assertSame($rows->count(), $rows->pluck('unit.id')->unique()->count());
        $this->assertSame(6, array_sum(array_column($response->viewData('trend'), 'total')));
    }

    public function test_unit_reporting_lists_every_unit_with_its_indicators_and_status(): void
    {
        $empty = Unit::create(['code' => 'RAD', 'name' => 'Radiologi', 'is_active' => true]);
        $inactive = Unit::create(['code' => 'OLD', 'name' => 'Unit Lama', 'is_active' => false]);
        $this->observe($this->impRs, $this->igd, times: 2);

        $response = $this->dashboard($this->admin);
        $rows = $response->viewData('unitRows')->keyBy(fn ($row) => $row['unit']->code);

        $this->assertEqualsCanonicalizing(['IGD', 'ICU', 'LAB', 'RAD'], $rows->keys()->all());
        $this->assertSame([5, 2, true], [$rows['IGD']['indicators'], $rows['IGD']['total'], $rows['IGD']['reported']]);
        $this->assertSame([3, 0, false], [$rows['ICU']['indicators'], $rows['ICU']['total'], $rows['ICU']['reported']]);
        $this->assertSame([1, 0, false], [$rows['LAB']['indicators'], $rows['LAB']['total'], $rows['LAB']['reported']]);
        $this->assertSame([0, 0, false], [$rows['RAD']['indicators'], $rows['RAD']['total'], $rows['RAD']['reported']]);

        $response->assertSee('Pelaporan Unit')->assertSee('Sudah melapor')->assertSee('Belum melapor')->assertSee('Belum ada indikator');

        // Deactivated indicators do not count as a unit's responsibility.
        $this->impRs->update(['is_active' => false]);
        $this->assertSame(4, $this->dashboard($this->admin)->viewData('unitRows')->firstWhere('unit.id', $this->igd->id)['indicators']);

        // An inactive unit appears only when it has data in the period.
        $this->observe($this->impUnit, $inactive);
        $this->assertTrue($this->dashboard($this->admin)->viewData('unitRows')->contains('unit.id', $inactive->id));
        $this->assertSame(0, $empty->observations()->count());
    }

    public function test_date_and_unit_filters_apply_to_every_part_of_the_dashboard(): void
    {
        $identification = $this->inm[ObservationForms::IDENTIFICATION];
        $lastMonth = now()->subMonthNoOverflow()->startOfMonth()->toDateString();

        $this->observe($identification, $this->igd, times: 2);
        $this->observe($identification, $this->icu);
        $this->observe($this->impRs, $this->icu, $lastMonth, times: 4);

        // Default period: this month only.
        $this->dashboard($this->admin)->assertViewHas('total', 3)->assertViewHas('reportingUnits', 2);

        // A wider period includes last month everywhere.
        $wide = $this->dashboard($this->admin, ['start_date' => $lastMonth, 'end_date' => now()->toDateString()]);
        $wide->assertViewHas('total', 7);
        $this->assertSame(4, $wide->viewData('indicators')->firstWhere('id', $this->impRs->id)->total);
        $this->assertSame(5, $wide->viewData('unitRows')->firstWhere('unit.id', $this->icu->id)['total']);

        // Last month only.
        $past = $this->dashboard($this->admin, ['start_date' => $lastMonth, 'end_date' => $lastMonth]);
        $past->assertViewHas('total', 4)->assertViewHas('reportingUnits', 1);
        $this->assertSame(0, $past->viewData('indicators')->firstWhere('id', $identification->id)->total);
        $this->assertFalse($past->viewData('unitRows')->firstWhere('unit.id', $this->igd->id)['reported']);

        // Unit filter: only that unit's indicators, observations and row.
        $icu = $this->dashboard($this->admin, ['unit_id' => $this->icu->id]);
        $icu->assertViewHas('total', 1)
            ->assertViewHas('activeIndicators', 3)
            ->assertViewHas('totalUnits', 1)
            ->assertViewHas('reportingUnits', 1)
            ->assertDontSee('Waktu Tunggu Hasil Lab');
        $this->assertEqualsCanonicalizing(
            [$identification->id, $this->inm[ObservationForms::PPE]->id, $this->impRs->id],
            $icu->viewData('indicators')->pluck('id')->all(),
        );
        $this->assertSame([$this->icu->id], $icu->viewData('unitRows')->pluck('unit.id')->all());
        // The trend spans six months, so it also holds the unit's four from last month.
        $this->assertSame(5, array_sum(array_column($icu->viewData('trend'), 'total')));

        // Invalid filters are rejected, not ignored.
        $this->actingAs($this->admin)->get(route('dashboard', ['start_date' => '2026-02-10', 'end_date' => '2026-02-01']))->assertSessionHasErrors('end_date');
        $this->actingAs($this->admin)->get(route('dashboard', ['unit_id' => 999999]))->assertSessionHasErrors('unit_id');
    }

    public function test_trend_covers_at_most_six_months(): void
    {
        $this->observe($this->impRs, $this->igd, now()->subMonthsNoOverflow(5)->startOfMonth()->toDateString());
        $this->observe($this->impRs, $this->igd, now()->subMonthsNoOverflow(6)->startOfMonth()->toDateString());
        $this->observe($this->impRs, $this->igd);

        $trend = $this->dashboard($this->admin)->viewData('trend');

        $this->assertCount(6, $trend);
        $this->assertSame(2, array_sum(array_column($trend, 'total')));
        $this->assertSame(1, $trend[5]['total']);
    }

    public function test_pic_mutu_sees_the_same_overview_as_admin(): void
    {
        $this->observe($this->impRs, $this->igd, times: 2);
        $picMutu = User::factory()->picMutu()->create();

        $admin = $this->dashboard($this->admin);
        $mutu = $this->dashboard($picMutu);

        foreach (['total', 'activeIndicators', 'totalUnits', 'reportingUnits'] as $key) {
            $this->assertSame($admin->viewData($key), $mutu->viewData($key));
        }
        $this->assertSame($admin->viewData('indicators')->pluck('id')->all(), $mutu->viewData('indicators')->pluck('id')->all());
        $this->assertSame($admin->viewData('unitRows')->pluck('unit.id')->all(), $mutu->viewData('unitRows')->pluck('unit.id')->all());

        // PIC Mutu can filter by unit but still cannot record observations.
        $this->dashboard($picMutu, ['unit_id' => $this->icu->id])->assertViewHas('totalUnits', 1);
        $mutu->assertSee('name="unit_id"', false)->assertDontSee('Input Observasi');
    }

    public function test_pic_unit_only_sees_their_own_unit(): void
    {
        $this->observe($this->impRs, $this->igd, times: 2);
        $this->observe($this->impRs, $this->icu, times: 5);
        $picUnit = User::factory()->picUnit($this->icu)->create();

        // A unit_id in the URL cannot widen the view.
        foreach ([[], ['unit_id' => $this->igd->id]] as $query) {
            $response = $this->dashboard($picUnit, $query);

            $response->assertViewHas('total', 5)
                ->assertViewHas('totalUnits', 1)
                ->assertViewHas('reportingUnits', 1)
                ->assertViewHas('activeIndicators', 3)
                ->assertDontSee('name="unit_id"', false)
                ->assertDontSee('Instalasi Gawat Darurat')
                ->assertDontSee('Laboratorium');

            $this->assertSame([$this->icu->id], $response->viewData('unitRows')->pluck('unit.id')->all());
            $this->assertEqualsCanonicalizing(
                [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id, $this->impRs->id],
                $response->viewData('indicators')->pluck('id')->all(),
            );
        }

        // The input page lists INM and IMP RS mapped to the unit; IMP RS
        // without a published form is listed but cannot be opened.
        $this->assertEqualsCanonicalizing(
            [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id, $this->impRs->id],
            $this->actingAs($picUnit)->get(route('observations.create'))->viewData('indicators')->pluck('id')->all(),
        );
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->impRs))->assertNotFound();

        // Dashboard: an input button for recordable indicators, a status for the rest.
        $response = $this->dashboard($picUnit);
        $this->assertEqualsCanonicalizing(
            [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->inm[ObservationForms::PPE]->id],
            $response->viewData('inputIds')->all(),
        );
        $this->assertSame([$this->impRs->id], $response->viewData('unpublishedIds')->all());
        $response->assertSee(route('observations.create.indicator', $this->inm[ObservationForms::PPE]))
            ->assertDontSee(route('observations.create.indicator', $this->impRs))
            ->assertSee('Formulir belum terbit');
    }

    public function test_pic_unit_without_a_unit_gets_an_empty_dashboard(): void
    {
        $this->observe($this->impRs, $this->igd);

        $this->dashboard(User::factory()->picUnit()->create())
            ->assertViewHas('total', 0)
            ->assertViewHas('indicators', fn ($indicators) => $indicators->isEmpty())
            ->assertViewHas('unitRows', fn ($rows) => $rows->isEmpty())
            ->assertSee('Akun Anda belum terhubung dengan unit yang aktif');
    }

    public function test_dashboard_reads_without_writing_anything(): void
    {
        $this->observe($this->impRs, $this->igd, times: 2);
        $snapshot = fn () => [
            DB::table('observations')->orderBy('id')->get()->toJson(),
            DB::table('indicator_unit')->orderBy('id')->get()->toJson(),
            DB::table('indicators')->orderBy('id')->get()->toJson(),
        ];
        $before = $snapshot();

        $this->dashboard($this->admin);
        $this->dashboard($this->admin, ['unit_id' => $this->igd->id]);
        $this->dashboard(User::factory()->picMutu()->create());

        $this->assertSame($before, $snapshot());
        $this->get(route('dashboard'))->assertOk()->assertSee('Dashboard Mutu')->assertSee('Ringkasan Indikator Mutu');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        auth()->logout();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}
