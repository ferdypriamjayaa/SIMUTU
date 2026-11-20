<?php

namespace Tests\Feature;

use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInmIndicators;
use Tests\Concerns\ReadsXlsx;
use Tests\TestCase;

/**
 * Accounts without data.view_all_units (PIC Unit, Validator) only reach their
 * own unit's data: dashboard, report, detail and export, whatever unit_id the
 * request carries. Admin reaches every unit.
 */
class UnitScopeTest extends TestCase
{
    use CreatesInmIndicators, ReadsXlsx, RefreshDatabase;

    private Unit $stroke;

    private Unit $other;

    private int $indicatorId;

    private Observation $strokeObservation;

    private Observation $otherObservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stroke = Unit::create(['code' => 'SC', 'name' => 'Stroke Centre', 'is_active' => true]);
        $this->other = Unit::create(['code' => 'ICU', 'name' => 'ICU', 'is_active' => true]);

        $inm = $this->createInmIndicators();
        $this->assignIndicators($this->stroke, $inm, [ObservationForms::IDENTIFICATION]);
        $this->assignIndicators($this->other, $inm, [ObservationForms::IDENTIFICATION]);
        $this->indicatorId = $inm[ObservationForms::IDENTIFICATION]->id;

        $this->strokeObservation = $this->observe($this->stroke, 'Pasien Stroke');
        $this->observe($this->stroke, 'Pasien Stroke Dua');
        $this->otherObservation = $this->observe($this->other, 'Pasien ICU Rahasia');
    }

    private function observe(Unit $unit, string $patient): Observation
    {
        return Observation::create([
            'indicator_id' => $this->indicatorId,
            'unit_id' => $unit->id,
            'user_id' => User::factory()->picUnit($unit)->create()->id,
            'observation_date' => '2026-11-10',
            'officer_name' => 'Petugas',
            'patient_name' => $patient,
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);
    }

    /** The reported situation: Validator at Stroke Centre with report export granted. */
    private function strokeValidator(): User
    {
        $validator = User::factory()->validator()->create(['unit_id' => $this->stroke->id]);
        $validator->permissionOverrides()->create(['permission' => 'inm.export', 'is_granted' => true]);

        return $validator->fresh();
    }

    private function report(array $query = []): array
    {
        return ['kategori' => 'inm', 'indicator_id' => $this->indicatorId, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', ...$query];
    }

    private function exportText(User $user, array $query = []): string
    {
        $bytes = $this->actingAs($user)->get(route('observations.export', ['format' => 'excel', ...$this->report($query)]))
            ->assertOk()->streamedContent();

        return implode("\n", $this->xlsxParts($bytes));
    }

    public function test_validator_role_is_limited_to_its_own_unit(): void
    {
        $this->assertFalse(User::factory()->validator()->create()->hasPermission(Permissions::VIEW_ALL_UNITS));
        $this->assertFalse(User::factory()->picUnit($this->stroke)->create()->canViewAllUnits());
        $this->assertTrue(User::factory()->admin()->create()->canViewAllUnits());
    }

    public function test_admin_sees_every_unit(): void
    {
        $admin = User::factory()->admin()->create();

        $this->assertCount(3, Observation::visibleTo($admin)->get());
        $this->assertSame(3, $this->actingAs($admin)->get(route('dashboard', ['start_date' => '2026-11-01', 'end_date' => '2026-11-30']))->viewData('total'));
        $this->assertSame(1, $this->actingAs($admin)->get(route('dashboard', ['start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'unit_id' => $this->other->id]))->viewData('total'));
        $this->actingAs($admin)->get(route('observations.show', $this->otherObservation))->assertOk();

        $text = $this->exportText($admin);
        $this->assertStringContainsString('Pasien Stroke', $text);
        $this->assertStringContainsString('Pasien ICU Rahasia', $text);
    }

    public function test_stroke_centre_validator_only_sees_stroke_centre(): void
    {
        $validator = $this->strokeValidator();

        // Dashboard: totals of Stroke Centre only, even when another unit is asked for.
        $dashboard = $this->actingAs($validator)->get(route('dashboard', ['start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'unit_id' => $this->other->id]))->assertOk();
        $this->assertSame(2, $dashboard->viewData('total'));
        $this->assertTrue($dashboard->viewData('scopeUnit')->is($this->stroke));
        $this->assertTrue($dashboard->viewData('units')->isEmpty());

        // Report: own unit only; another unit_id is refused.
        $this->actingAs($validator)->get(route('observations.index', $this->report()))->assertOk()
            ->assertSee('Pasien Stroke')->assertDontSee('Pasien ICU Rahasia');
        $this->actingAs($validator)->get(route('observations.index', $this->report(['unit_id' => $this->other->id])))->assertSessionHasErrors('unit_id');

        // Detail.
        $this->actingAs($validator)->get(route('observations.show', $this->strokeObservation))->assertOk();
        $this->actingAs($validator)->get(route('observations.show', $this->otherObservation))->assertForbidden();
    }

    public function test_export_never_contains_another_units_data(): void
    {
        $validator = $this->strokeValidator();

        $text = $this->exportText($validator);
        $this->assertStringContainsString('Pasien Stroke', $text);
        $this->assertStringNotContainsString('Pasien ICU Rahasia', $text);

        foreach (['excel', 'pdf'] as $format) {
            $this->actingAs($validator)->get(route('observations.export', ['format' => $format, ...$this->report(['unit_id' => $this->other->id])]))
                ->assertSessionHasErrors('unit_id');
        }
    }

    public function test_pic_unit_only_sees_its_unit(): void
    {
        $pic = User::factory()->picUnit($this->other)->create();

        $this->assertSame([$this->otherObservation->id], Observation::visibleTo($pic)->pluck('id')->all());
        $this->assertSame(1, $this->actingAs($pic)->get(route('dashboard', ['start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'unit_id' => $this->stroke->id]))->viewData('total'));
        $this->actingAs($pic)->get(route('observations.show', $this->strokeObservation))->assertForbidden();
        $this->actingAs($pic)->get(route('observations.index', $this->report(['unit_id' => $this->stroke->id])))->assertSessionHasErrors('unit_id');
    }
}
