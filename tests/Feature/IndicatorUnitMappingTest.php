<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class IndicatorUnitMappingTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    /** @var array<string, Indicator> */
    private array $inm;

    private User $admin;

    private Unit $igd;

    private Unit $icu;

    private Unit $lab;

    private Indicator $impRs;

    private Indicator $impUnit;

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
        $this->impUnit = Indicator::create(['indicator_category_id' => $unit->id, 'unit_id' => $this->lab->id, 'name' => 'Waktu Tunggu Hasil Lab', 'is_active' => true]);
    }

    private function mapped(Indicator $indicator): array
    {
        return $indicator->assignedUnits()->orderBy('units.id')->pluck('units.id')->all();
    }

    // --- list, search, filters ---------------------------------------------------

    public function test_admin_sees_all_indicators_with_units_and_mapping_status(): void
    {
        $this->impRs->assignedUnits()->attach([$this->igd->id, $this->icu->id]);

        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.index'))
            ->assertOk()
            ->assertViewHas('totals', ['all' => 6, 'mapped' => 1, 'unmapped' => 5])
            ->assertSee('Kepatuhan SBAR')
            ->assertSee('Waktu Tunggu Hasil Lab')
            ->assertSee($this->inm[ObservationForms::PPE]->name)
            ->assertSee('Dipetakan ke 2 unit')
            ->assertSee('Belum dipetakan')
            ->assertSee('Instalasi Gawat Darurat')
            ->assertSee('Intensive Care Unit')
            ->assertSee('Penanggung jawab: Laboratorium')
            ->assertSee(route('indicator-unit-mappings.destroy', [$this->impRs, $this->igd]));

        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('indicator-unit-mappings.index'));
    }

    public function test_search_and_filters_narrow_the_list(): void
    {
        $this->impRs->assignedUnits()->attach($this->igd->id);
        $names = fn (array $query) => $this->actingAs($this->admin)
            ->get(route('indicator-unit-mappings.index', $query))->assertOk()
            ->viewData('indicators')->pluck('name')->all();

        $this->assertSame(['Kepatuhan SBAR'], $names(['q' => 'sbar']));
        $this->assertSame([], $names(['q' => '100%']));
        $this->assertCount(4, $names(['category' => 'inm']));
        $this->assertSame(['Kepatuhan SBAR'], $names(['category' => 'imp-rs']));
        $this->assertSame(['Waktu Tunggu Hasil Lab'], $names(['category' => 'imp-unit']));
        $this->assertSame(['Kepatuhan SBAR'], $names(['status' => 'mapped']));
        $this->assertCount(5, $names(['status' => 'unmapped']));
        $this->assertSame([], $names(['status' => 'unmapped', 'category' => 'imp-rs']));
        $this->assertSame(['Kepatuhan Identifikasi Pasien'], $names(['q' => 'identifikasi', 'category' => 'inm', 'status' => 'unmapped']));

        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.index', ['category' => 'lainnya']))->assertSessionHasErrors('category');
        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.index', ['q' => 'tidak ada']))
            ->assertSee('Tidak ada indikator yang cocok dengan filter ini.');
    }

    // --- adding, editing, removing -------------------------------------------------

    public function test_admin_can_add_edit_and_remove_units_of_an_indicator(): void
    {
        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.edit', $this->impRs))
            ->assertOk()
            ->assertSee('Instalasi Gawat Darurat')
            ->assertSee('Laboratorium')
            ->assertDontSee('belum memengaruhi hak akses');

        // Add several at once.
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->impRs), ['unit_ids' => [$this->igd->id, $this->icu->id]])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('indicator-unit-mappings.index'));
        $this->assertSame([$this->igd->id, $this->icu->id], $this->mapped($this->impRs));

        $kept = DB::table('indicator_unit')->where('indicator_id', $this->impRs->id)->where('unit_id', $this->icu->id)->value('id');

        // Edit: one out, one in, one untouched (same row).
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->impRs), ['unit_ids' => [$this->icu->id, $this->lab->id]])
            ->assertSessionHas('success', 'Mapping unit untuk "Kepatuhan SBAR" diperbarui: 1 ditambahkan, 1 dihapus.');
        $this->assertSame([$this->icu->id, $this->lab->id], $this->mapped($this->impRs));
        $this->assertSame($kept, DB::table('indicator_unit')->where('indicator_id', $this->impRs->id)->where('unit_id', $this->icu->id)->value('id'));

        // The edit page shows the current selection.
        $html = $this->actingAs($this->admin)->get(route('indicator-unit-mappings.edit', $this->impRs))->getContent();
        $this->assertMatchesRegularExpression('/value="'.$this->lab->id.'"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="'.$this->igd->id.'"\s+checked/', $html);

        // Remove a single unit from the list page.
        $this->actingAs($this->admin)->from(route('indicator-unit-mappings.index'))
            ->delete(route('indicator-unit-mappings.destroy', [$this->impRs, $this->icu]))
            ->assertRedirect(route('indicator-unit-mappings.index'))
            ->assertSessionHas('success');
        $this->assertSame([$this->lab->id], $this->mapped($this->impRs));

        // Removing something that is not mapped changes nothing.
        $this->actingAs($this->admin)->delete(route('indicator-unit-mappings.destroy', [$this->impRs, $this->icu]))->assertSessionHas('error');

        // Clear everything.
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->impRs), [])->assertSessionHasNoErrors();
        $this->assertSame([], $this->mapped($this->impRs));
    }

    public function test_one_unit_can_belong_to_many_indicators_and_one_indicator_to_many_units(): void
    {
        foreach ([$this->impRs, $this->impUnit] as $indicator) {
            $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $indicator), ['unit_ids' => [$this->igd->id, $this->lab->id]])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame([$this->igd->id, $this->lab->id], $this->mapped($this->impRs));
        $this->assertSame([$this->igd->id, $this->lab->id], $this->mapped($this->impUnit));
        $this->assertSame(2, $this->igd->assignedIndicators()->count());

        // The responsible unit of an IMP Unit indicator is a separate setting.
        $this->assertSame($this->lab->id, $this->impUnit->fresh()->unit_id);
    }

    public function test_duplicate_and_invalid_mappings_are_rejected(): void
    {
        $inactive = Unit::create(['code' => 'OLD', 'name' => 'Unit Lama', 'is_active' => false]);
        $url = route('indicator-unit-mappings.update', $this->impRs);

        $this->actingAs($this->admin)->put($url, ['unit_ids' => [$this->igd->id, $this->igd->id]])->assertSessionHasErrors(['unit_ids.0']);
        $this->actingAs($this->admin)->put($url, ['unit_ids' => [999999]])->assertSessionHasErrors('unit_ids.0');
        $this->actingAs($this->admin)->put($url, ['unit_ids' => [$inactive->id]])->assertSessionHasErrors('unit_ids.0');
        $this->actingAs($this->admin)->put($url, ['unit_ids' => 'semua'])->assertSessionHasErrors('unit_ids');
        $this->assertSame([], $this->mapped($this->impRs));

        // Saving the same selection twice stores each pair once.
        $this->actingAs($this->admin)->put($url, ['unit_ids' => [$this->igd->id]]);
        $this->actingAs($this->admin)->put($url, ['unit_ids' => [$this->igd->id]])
            ->assertSessionHas('success', 'Mapping unit untuk "Kepatuhan SBAR" tidak berubah.');
        $this->assertSame(1, DB::table('indicator_unit')->where('indicator_id', $this->impRs->id)->count());

        // A unit that became inactive stays mapped and can still be removed.
        $this->igd->update(['is_active' => false]);
        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.edit', $this->impRs))->assertSee('unit nonaktif')->assertDontSee('Unit Lama');
        $this->actingAs($this->admin)->put($url, ['unit_ids' => [$this->igd->id, $this->icu->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->igd->id, $this->icu->id], $this->mapped($this->impRs));

        // The database itself refuses a duplicate pair.
        $this->expectException(QueryException::class);
        DB::table('indicator_unit')->insert(['indicator_id' => $this->impRs->id, 'unit_id' => $this->igd->id]);
    }

    public function test_unknown_indicator_or_unit_is_not_found(): void
    {
        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.edit', 999999))->assertNotFound();
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', 999999), [])->assertNotFound();
        $this->actingAs($this->admin)->delete(route('indicator-unit-mappings.destroy', [$this->impRs, 999999]))->assertNotFound();
    }

    // --- nothing else changes ----------------------------------------------------------

    /**
     * An IMP RS mapping lists the indicator on the unit's input page, but it
     * can only be recorded once its form is published. IMP Unit mappings
     * stay administrative only.
     */
    public function test_imp_mappings_do_not_open_input_without_a_published_form(): void
    {
        $picUnit = User::factory()->picUnit($this->igd)->create();
        $this->assignIndicators($this->igd, $this->inm, [ObservationForms::PPE]);

        $create = fn () => $this->actingAs($picUnit)->get(route('observations.create'))->viewData('indicators')->pluck('id')->all();
        $history = fn () => $this->actingAs($picUnit)->get(route('observations.index'))->viewData('indicators')->pluck('id')->all();

        $before = ['create' => $create(), 'history' => $history()];

        // Map both IMP indicators to units, including one with no INM assignment at all.
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->impRs), ['unit_ids' => [$this->igd->id, $this->lab->id]]);
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->impUnit), ['unit_ids' => [$this->igd->id, $this->icu->id]]);

        // Both are listed on the input page as cards that cannot be opened
        // (IMP RS: no published form; IMP Unit: not open for input); the
        // history filter is unchanged.
        $this->assertSame(['create' => [...$before['create'], $this->impRs->id, $this->impUnit->id], 'history' => $before['history']], ['create' => $create(), 'history' => $history()]);
        $statuses = $this->actingAs($picUnit)->get(route('observations.create'))->viewData('indicators')->pluck('input_status', 'id');
        $this->assertSame('unpublished', $statuses[$this->impRs->id]);
        $this->assertSame('closed', $statuses[$this->impUnit->id]);

        // The dashboard reports the mappings (see DashboardTest).
        $listed = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->viewData('indicators')->keyBy('id');
        $this->assertSame(2, $listed[$this->impRs->id]->assigned_units_count);

        // Neither can be recorded: IMP RS has no published form, IMP Unit is not an input category.
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->impUnit))->assertNotFound();
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $this->impRs))->assertNotFound();
        $this->actingAs($picUnit)->post(route('observations.store'), ['indicator_id' => $this->impRs->id, 'observation_date' => now()->toDateString()])
            ->assertSessionHasErrors('indicator_id');

        // The unit list shows no indicator mappings at all.
        $this->actingAs($this->admin)->get(route('units.index'))->assertOk()->assertDontSee('SBAR')->assertDontSee('Waktu Tunggu Hasil Lab');

        // Changing an INM mapping leaves the IMP mappings of the same unit alone.
        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $this->inm[ObservationForms::IDENTIFICATION]), ['unit_ids' => [$this->igd->id]])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->delete(route('indicator-unit-mappings.destroy', [$this->inm[ObservationForms::PPE], $this->igd]))
            ->assertSessionHas('success');
        $this->assertSame([$this->igd->id, $this->lab->id], $this->mapped($this->impRs));
        $this->assertEqualsCanonicalizing(
            [$this->inm[ObservationForms::IDENTIFICATION]->id, $this->impRs->id, $this->impUnit->id],
            $this->igd->assignedIndicators()->pluck('indicators.id')->all(),
        );
    }

    public function test_inm_mapping_decides_which_units_can_record_the_indicator(): void
    {
        $ppe = $this->inm[ObservationForms::PPE];
        $picUnit = User::factory()->picUnit($this->igd)->create();

        $this->actingAs($this->admin)->get(route('indicator-unit-mappings.edit', $ppe))->assertOk()->assertSee('Indikator INM');

        $this->actingAs($this->admin)->put(route('indicator-unit-mappings.update', $ppe), ['unit_ids' => [$this->igd->id]])->assertSessionHasNoErrors();

        $this->assertTrue($this->igd->assignedIndicators()->whereKey($ppe->id)->exists());
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $ppe))->assertOk();
        $this->actingAs(User::factory()->picUnit($this->icu)->create())->get(route('observations.create.indicator', $ppe))->assertForbidden();

        // Removing the mapping stops new input from that unit.
        $this->actingAs($this->admin)->delete(route('indicator-unit-mappings.destroy', [$ppe, $this->igd]))->assertSessionHas('success');
        $this->actingAs($picUnit)->get(route('observations.create.indicator', $ppe))->assertForbidden();
    }

    // --- access -------------------------------------------------------------------------

    public static function nonAdminRoles(): array
    {
        return ['pic mutu' => [User::ROLE_PIC_MUTU], 'pic unit' => [User::ROLE_PIC_UNIT]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_admin_can_manage_mappings(string $role): void
    {
        $this->impRs->assignedUnits()->attach($this->igd->id);
        $user = User::factory()->create(['role' => $role, 'unit_id' => $this->igd->id]);

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('indicator-unit-mappings.index'));
        $this->get(route('indicator-unit-mappings.index'))->assertForbidden();
        $this->get(route('indicator-unit-mappings.edit', $this->impRs))->assertForbidden();
        $this->put(route('indicator-unit-mappings.update', $this->impRs), ['unit_ids' => [$this->icu->id]])->assertForbidden();
        $this->delete(route('indicator-unit-mappings.destroy', [$this->impRs, $this->igd]))->assertForbidden();

        $this->assertSame([$this->igd->id], $this->mapped($this->impRs));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('indicator-unit-mappings.index'))->assertRedirect(route('login'));
        $this->put(route('indicator-unit-mappings.update', $this->impRs), [])->assertRedirect(route('login'));
    }
}
