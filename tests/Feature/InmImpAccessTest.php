<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Observation;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Tests\Concerns\BuildsIndicatorForms;
use Tests\Concerns\CreatesInmIndicators;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * INM and IMP access are independent (inm.* / imp.*), on top of the menu
 * permissions (observations.create, reports.view), and enforced in routes,
 * queries and exports.
 */
class InmImpAccessTest extends TestCase
{
    use BuildsIndicatorForms, CreatesInmIndicators, RefreshDatabase;

    private Unit $unit;

    private Indicator $inmIndicator;

    private Indicator $impIndicator;

    private Observation $inmObservation;

    private Observation $impObservation;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'NS3B', 'name' => 'Perawat Lantai 3 Gedung B', 'is_active' => true]);

        $inm = $this->createInmIndicators();
        $this->assignIndicators($this->unit, $inm, [ObservationForms::IDENTIFICATION]);
        $this->inmIndicator = $inm[ObservationForms::IDENTIFICATION];

        $rs = IndicatorCategory::forceCreate(['code' => 'IMP_RS', 'slug' => 'imp-rs', 'name' => 'IMP RS']);
        IndicatorCategory::forceCreate(['code' => 'IMP_UNIT', 'slug' => 'imp-unit', 'name' => 'IMP Unit']);
        $this->impIndicator = Indicator::create(['indicator_category_id' => $rs->id, 'name' => 'Kepatuhan Hand Hygiene', 'is_active' => true]);
        $version = $this->publishNewVersion($admin, $this->impIndicator, [
            ['label' => 'Nama Pasien', 'type' => 'text', 'key' => 'patient_name', 'is_required' => true],
        ]);
        $this->impIndicator->assignedUnits()->attach($this->unit->id);

        $author = User::factory()->picUnit($this->unit)->create();
        $this->inmObservation = Observation::create([
            'indicator_id' => $this->inmIndicator->id,
            'unit_id' => $this->unit->id,
            'user_id' => $author->id,
            'observation_date' => '2026-11-10',
            'officer_name' => 'Petugas',
            'patient_name' => 'Pasien Contoh',
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);
        $this->impObservation = Observation::create([
            'indicator_id' => $this->impIndicator->id,
            'indicator_form_version_id' => $version->id,
            'unit_id' => $this->unit->id,
            'user_id' => $author->id,
            'observation_date' => '2026-11-10',
        ]);
    }

    /**
     * A unit account with the menus and the given area permissions.
     *
     * @param  list<string>  $areas  e.g. ['inm.view', 'inm.create']
     */
    private function userWith(array $areas, array $extra = []): User
    {
        $role = Role::create(['slug' => 'r'.Role::count(), 'name' => 'Peran '.Role::count()]);
        $role->syncPermissions(['dashboard.view', 'observations.create', 'reports.view', ...$areas, ...$extra]);

        return User::factory()->create(['role' => $role->slug, 'unit_id' => $this->unit->id]);
    }

    private function exportUrl(Indicator $indicator, string $format = 'excel'): string
    {
        return route('observations.export', ['format' => $format, 'indicator_id' => $indicator->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    }

    public function test_inm_only_user_cannot_reach_imp(): void
    {
        $user = $this->userWith(['inm.view', 'inm.create', 'inm.edit', 'inm.delete', 'inm.export']);

        // Report: INM tab only; IMP tabs and indicators are refused.
        $this->actingAs($user)->get(route('observations.index'))->assertOk()
            ->assertSee('kategori=inm', false)->assertDontSee('kategori=imp-rs', false);
        $this->actingAs($user)->get(route('observations.index', ['kategori' => 'imp-rs']))->assertSessionHasErrors('kategori');
        $this->actingAs($user)->get(route('observations.index', ['kategori' => 'inm', 'indicator_id' => $this->impIndicator->id]))->assertSessionHasErrors('indicator_id');
        $this->assertSame([$this->inmObservation->id], Observation::visibleTo($user)->pluck('id')->all());

        // Direct URLs.
        $this->actingAs($user)->get(route('observations.show', $this->inmObservation))->assertOk();
        $this->actingAs($user)->get(route('observations.show', $this->impObservation))->assertForbidden();
        $this->actingAs($user)->get(route('observations.edit', $this->inmObservation))->assertOk();
        $this->actingAs($user)->get(route('observations.edit', $this->impObservation))->assertForbidden();
        $this->actingAs($user)->delete(route('observations.destroy', $this->impObservation))->assertForbidden();
        $this->assertModelExists($this->impObservation);

        // Input: only INM indicators.
        $this->actingAs($user)->get(route('observations.create'))->assertOk()
            ->assertSee($this->inmIndicator->name)->assertDontSee($this->impIndicator->name);
        $this->actingAs($user)->get(route('observations.create.indicator', $this->inmIndicator))->assertOk();
        $this->actingAs($user)->get(route('observations.create.indicator', $this->impIndicator))->assertForbidden();
        $this->actingAs($user)->post(route('observations.store'), ['indicator_id' => $this->impIndicator->id, 'patient_name' => 'X', 'observation_date' => '2026-11-10'])
            ->assertSessionHasErrors('indicator_id');

        // Export.
        $this->actingAs($user)->get($this->exportUrl($this->inmIndicator))->assertOk();
        $this->actingAs($user)->get($this->exportUrl($this->impIndicator, 'pdf'))->assertSessionHasErrors('indicator_id');
    }

    public function test_imp_only_user_cannot_reach_inm(): void
    {
        $user = $this->userWith(['imp.view', 'imp.create', 'imp.edit', 'imp.delete', 'imp.export']);

        $this->actingAs($user)->get(route('observations.index'))->assertOk()
            ->assertSee('kategori=imp-rs', false)->assertDontSee('kategori=inm', false);
        $this->assertSame([$this->impObservation->id], Observation::visibleTo($user)->pluck('id')->all());

        $this->actingAs($user)->get(route('observations.show', $this->impObservation))->assertOk();
        $this->actingAs($user)->get(route('observations.show', $this->inmObservation))->assertForbidden();
        $this->actingAs($user)->get(route('observations.edit', $this->inmObservation))->assertForbidden();
        $this->actingAs($user)->delete(route('observations.destroy', $this->inmObservation))->assertForbidden();
        $this->assertModelExists($this->inmObservation);

        $this->actingAs($user)->get(route('observations.create.indicator', $this->impIndicator))->assertOk();
        $this->actingAs($user)->get(route('observations.create.indicator', $this->inmIndicator))->assertForbidden();

        $this->actingAs($user)->get($this->exportUrl($this->impIndicator))->assertOk();
        $this->actingAs($user)->get($this->exportUrl($this->inmIndicator))->assertSessionHasErrors('indicator_id');

        // The dashboard lists only IMP indicators.
        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee($this->impIndicator->name)->assertDontSee($this->inmIndicator->name);
    }

    public function test_both_and_neither(): void
    {
        $both = $this->userWith(['inm.view', 'imp.view']);
        $this->assertCount(2, Observation::visibleTo($both)->get());
        $this->actingAs($both)->get(route('observations.show', $this->inmObservation))->assertOk();
        $this->actingAs($both)->get(route('observations.show', $this->impObservation))->assertOk();

        // Menus alone open nothing.
        $neither = $this->userWith([]);
        $this->actingAs($neither)->get(route('observations.index'))->assertForbidden();
        $this->actingAs($neither)->get(route('observations.create'))->assertForbidden();
        $this->actingAs($neither)->get(route('observations.show', $this->inmObservation))->assertForbidden();
        $this->assertCount(0, Observation::visibleTo($neither)->get());
        $this->actingAs($neither)->get(route('dashboard'))->assertOk()->assertDontSee('Laporan Observasi');
    }

    public function test_export_needs_the_category_export_permission(): void
    {
        $viewer = $this->userWith(['inm.view', 'imp.view', 'imp.export']);

        $this->actingAs($viewer)->get(route('observations.index', ['kategori' => 'inm', 'indicator_id' => $this->inmIndicator->id]))
            ->assertOk()->assertDontSee(route('observations.export', ['format' => 'excel']), false);
        $this->actingAs($viewer)->get($this->exportUrl($this->inmIndicator))->assertForbidden();
        $this->actingAs($viewer)->get($this->exportUrl($this->inmIndicator, 'pdf'))->assertForbidden();
        $this->actingAs($viewer)->get($this->exportUrl($this->impIndicator))->assertOk();
    }

    public function test_role_inheritance_and_user_overrides_per_area(): void
    {
        // PIC Unit inherits INM and IMP from its role (converted from the old permissions).
        $picUnit = User::factory()->picUnit($this->unit)->create();
        foreach (['inm.view', 'inm.create', 'inm.edit', 'inm.delete', 'imp.view', 'imp.create', 'imp.edit', 'imp.delete', 'imp_unit.view', 'imp_unit.edit', 'imp_unit.delete'] as $permission) {
            $this->assertTrue($picUnit->hasPermission($permission), $permission);
        }
        $this->assertFalse($picUnit->hasPermission('inm.export'));

        // A restriction removes IMP for this account only.
        $picUnit->permissionOverrides()->create(['permission' => 'imp.view', 'is_granted' => false]);
        $picUnit = $picUnit->fresh();
        $this->actingAs($picUnit)->get(route('observations.show', $this->impObservation))->assertForbidden();
        $this->actingAs($picUnit)->get(route('observations.show', $this->inmObservation))->assertOk();

        // A grant adds INM export for this account only.
        $picUnit->permissionOverrides()->create(['permission' => 'inm.export', 'is_granted' => true]);
        $this->actingAs($picUnit->fresh())->get($this->exportUrl($this->inmIndicator))->assertOk();
        $this->assertFalse(User::factory()->picUnit($this->unit)->create()->hasPermission('inm.export'));
    }

    public function test_permission_tree_renders_folders_with_parent_checkboxes(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->picUnit($this->unit)->create();
        $role = Role::where('slug', 'pic_unit')->firstOrFail();

        foreach (["/users/{$target->id}/edit", "/roles/{$role->id}/edit", "/roles/{$role->id}"] as $url) {
            $response = $this->actingAs($admin)->get($url)->assertOk();
            foreach (['menu', 'master', 'integrations', 'inm', 'imp', 'imp_unit', 'master.roles', 'integrations.jotform'] as $folder) {
                $response->assertSee('data-folder="'.$folder.'"', false);
            }
            $response->assertSee('permissionTree(', false)->assertSee('IMP RS')->assertSee('IMP Unit')->assertSee('Tarik data (Excel/PDF)')->assertSee('Mapping Unit HRIS')
                // Identifiers stay internal: never printed as text, only in attributes.
                ->assertDontSee('>inm.view<', false)->assertDontSee('View:');
        }

        $this->actingAs($admin)->get("/roles/{$role->id}/edit")->assertSee('name="permissions[]" value="inm.view"', false);
        $this->actingAs($admin)->get("/roles/{$role->id}")->assertDontSee('name="permissions[]"', false);
        $this->actingAs($admin)->get("/users/{$target->id}/edit")->assertSee('name="overrides[imp.export]"', false);
    }
    public function test_imp_rs_and_imp_unit_are_separate(): void
    {
        $impUnitIndicator = Indicator::create([
            'indicator_category_id' => IndicatorCategory::where('code', 'IMP_UNIT')->value('id'),
            'unit_id' => $this->unit->id, 'name' => 'Waktu Tanggap Perawat', 'is_active' => true,
        ]);
        $version = $this->publishNewVersion(User::factory()->admin()->create(), $impUnitIndicator, [
            ['label' => 'Nama Pasien', 'type' => 'text', 'key' => 'patient_name', 'is_required' => true],
        ]);
        $impUnitObservation = Observation::create([
            'indicator_id' => $impUnitIndicator->id, 'indicator_form_version_id' => $version->id,
            'unit_id' => $this->unit->id, 'user_id' => $this->impObservation->user_id, 'observation_date' => '2026-11-10',
        ]);

        // IMP RS only: no IMP Unit tab, observation or export.
        $rs = $this->userWith(['imp.view', 'imp.export']);
        $this->actingAs($rs)->get(route('observations.index'))->assertOk()->assertSee('kategori=imp-rs', false)->assertDontSee('kategori=imp-unit', false);
        $this->assertSame([$this->impObservation->id], Observation::visibleTo($rs)->pluck('id')->all());
        $this->actingAs($rs)->get(route('observations.show', $impUnitObservation))->assertForbidden();
        $this->actingAs($rs)->get($this->exportUrl($impUnitIndicator))->assertSessionHasErrors('indicator_id');

        // IMP Unit only: the reverse.
        $unitOnly = $this->userWith(['imp_unit.view', 'imp_unit.export']);
        $this->actingAs($unitOnly)->get(route('observations.index'))->assertOk()->assertSee('kategori=imp-unit', false)->assertDontSee('kategori=imp-rs', false);
        $this->assertSame([$impUnitObservation->id], Observation::visibleTo($unitOnly)->pluck('id')->all());
        $this->actingAs($unitOnly)->get(route('observations.show', $this->impObservation))->assertForbidden();
        $this->actingAs($unitOnly)->get(route('observations.show', $impUnitObservation))->assertOk();
        $this->actingAs($unitOnly)->get($this->exportUrl($impUnitIndicator))->assertOk();
        $this->actingAs($unitOnly)->get($this->exportUrl($this->impIndicator))->assertSessionHasErrors('indicator_id');
    }

    public function test_delete_is_separate_from_edit(): void
    {
        $editor = $this->userWith(['inm.view', 'inm.edit']);
        $this->actingAs($editor)->get(route('observations.edit', $this->inmObservation))->assertOk();
        $this->actingAs($editor)->delete(route('observations.destroy', $this->inmObservation))->assertForbidden();
        $this->assertModelExists($this->inmObservation);

        $deleter = $this->userWith(['inm.view', 'inm.delete']);
        $this->actingAs($deleter)->get(route('observations.edit', $this->inmObservation))->assertForbidden();
        $this->actingAs($deleter)->delete(route('observations.destroy', $this->inmObservation))->assertRedirect();
        $this->assertModelMissing($this->inmObservation);
    }
}
