<?php

namespace Tests\Feature;

use App\Models\Indicator;
use App\Models\Observation;
use App\Models\Unit;
use App\Models\User;
use App\Support\ObservationForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class UnitDeletionTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    private User $admin;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->unit = Unit::create(['code' => 'IGD', 'name' => 'Instalasi Gawat Darurat', 'is_active' => true]);
    }

    public function test_admin_can_delete_unused_unit_and_its_inm_assignments(): void
    {
        $indicators = $this->createInmIndicators();
        $this->assignIndicators($this->unit, $indicators);

        $this->actingAs($this->admin)->get(route('units.index'))
            ->assertSee('action="'.route('units.destroy', $this->unit).'"', false);

        $this->actingAs($this->admin)->delete(route('units.destroy', $this->unit))
            ->assertRedirect(route('units.index'))
            ->assertSessionHas('success');

        $this->assertModelMissing($this->unit);
        $this->assertSame(0, DB::table('indicator_unit')->where('unit_id', $this->unit->id)->count());
        // The INM indicators themselves stay.
        $this->assertSame(count($indicators), Indicator::count());
    }

    public function test_unit_with_observations_cannot_be_deleted(): void
    {
        $indicators = $this->createInmIndicators();
        $author = User::factory()->picUnit($this->unit)->create();
        $author->update(['unit_id' => null]);

        Observation::create([
            'indicator_id' => $indicators[ObservationForms::IDENTIFICATION]->id,
            'unit_id' => $this->unit->id,
            'user_id' => $author->id,
            'observation_date' => now()->toDateString(),
            'officer_name' => 'Petugas',
            'patient_name' => 'Pasien',
            'medication_compliant' => true,
            'procedure_compliant' => true,
            'patient_name_verified' => true,
            'birth_date_verified' => true,
        ]);

        $this->actingAs($this->admin)->delete(route('units.destroy', $this->unit))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 data observasi')
                && str_contains($message, 'nonaktifkan'));

        $this->assertModelExists($this->unit);
    }

    public function test_unit_with_users_cannot_be_deleted(): void
    {
        $user = User::factory()->picUnit($this->unit)->create();

        $this->actingAs($this->admin)->delete(route('units.destroy', $this->unit))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 akun pengguna'));

        $this->assertModelExists($this->unit);
        $this->assertSame($this->unit->id, $user->fresh()->unit_id);
    }

    public function test_unit_responsible_for_imp_unit_indicator_cannot_be_deleted(): void
    {
        $categoryId = DB::table('indicator_categories')->insertGetId([
            'code' => 'IMP_UNIT', 'slug' => 'imp-unit', 'name' => 'IMP Unit', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Indicator::create(['indicator_category_id' => $categoryId, 'unit_id' => $this->unit->id, 'name' => 'Waktu tunggu', 'is_active' => true]);

        $this->actingAs($this->admin)->delete(route('units.destroy', $this->unit))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, '1 indikator IMP Unit'));

        $this->assertModelExists($this->unit);
    }

    public function test_only_admin_can_delete_units(): void
    {
        foreach ([User::factory()->picMutu()->create(), User::factory()->picUnit()->create()] as $user) {
            $this->actingAs($user)->delete(route('units.destroy', $this->unit))->assertForbidden();
        }

        $this->assertModelExists($this->unit);
    }
}
