<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Support\ObservationForms;
use Database\Seeders\InmUnitAssignmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesInmIndicators;
use Tests\TestCase;

class InmUnitAssignmentSeederTest extends TestCase
{
    use CreatesInmIndicators, RefreshDatabase;

    public function test_seeder_assigns_listed_indicators_and_can_rerun(): void
    {
        $indicators = $this->createInmIndicators();
        $igd = Unit::create(['code' => 'IGD', 'name' => 'Nama Lama IGD', 'is_active' => true]);
        $this->assignIndicators($igd, $indicators);

        $this->seed(InmUnitAssignmentSeeder::class);
        $this->seed(InmUnitAssignmentSeeder::class);

        $this->assertSame(16, Unit::count());
        $this->assertSame('Nama Lama IGD', $igd->fresh()->name);

        $assigned = fn (string $code) => Unit::where('code', $code)->sole()
            ->assignedIndicators()->pluck('form_type')->sort()->values()->all();

        $this->assertSame([ObservationForms::FALL_PREVENTION, ObservationForms::PPE], $assigned('IGD'));
        $this->assertSame([ObservationForms::IDENTIFICATION], $assigned('LAB'));
        $this->assertSame([ObservationForms::PPE], $assigned('CSSD'));
        $this->assertCount(4, $assigned('NS4B'));
    }
}
