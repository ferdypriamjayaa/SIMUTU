<?php

namespace Database\Seeders;

use App\Models\Indicator;
use App\Models\Unit;
use App\Support\ObservationForms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * INM indicators listed for each unit in the hospital's indicator list.
 *
 * Safe to re-run: missing units are created, existing unit names are left
 * alone, and each unit's INM assignments are synced to this list. Other
 * indicator assignments and all observations are untouched.
 */
class InmUnitAssignmentSeeder extends Seeder
{
    private const I = ObservationForms::IDENTIFICATION;

    private const F = ObservationForms::FALL_PREVENTION;

    private const V = ObservationForms::SPECIALIST_VISIT;

    private const P = ObservationForms::PPE;

    /**
     * code => [name used only when the unit is new, INM form types]
     */
    private const UNITS = [
        'NS2A' => ['Perawat Lantai 2 Gedung A', [self::I, self::F, self::V, self::P]],
        'NS3A' => ['Perawat Lantai 3 Gedung A', [self::I, self::F, self::V, self::P]],
        'NS4A' => ['Perawat Lantai 4 Gedung A', [self::I, self::F, self::V, self::P]],
        'NS2B' => ['Perawat Lantai 2 Gedung B', [self::I, self::F, self::V, self::P]],
        'NS3B' => ['Perawat Lantai 3 Gedung B', [self::I, self::F, self::V, self::P]],
        'NS4B' => ['Perawat Lantai 4 Gedung B', [self::I, self::F, self::V, self::P]],
        'NS2C' => ['Perawat Lantai 2 Gedung C', [self::I, self::F, self::V, self::P]],
        'LAB' => ['Laboratorium', [self::I]],
        'ICU' => ['Intensive Care Unit', [self::I, self::F, self::V, self::P]],
        'NICU' => ['Neonatal Intensive Care Unit', [self::I, self::F, self::V, self::P]],
        'HEMODIALISA' => ['Hemodialisa', [self::I, self::P]],
        'RADIOLOGI' => ['Radiologi', [self::I, self::P]],
        'REHAB_MEDIK' => ['Rehabilitasi Medik', [self::I, self::P]],
        'IGD' => ['Instalasi Gawat Darurat', [self::F, self::P]],
        'STROKE_CENTRE' => ['Stroke Centre', [self::F, self::V, self::P]],
        'CSSD' => ['Central Sterile Supply Department', [self::P]],
    ];

    public function run(): void
    {
        $inm = Indicator::whereNotNull('form_type')
            ->whereHas('category', fn ($query) => $query->where('code', 'INM'))
            ->pluck('id', 'form_type');

        DB::transaction(function () use ($inm) {
            foreach (self::UNITS as $code => [$name, $formTypes]) {
                $unit = Unit::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);

                $selected = $inm->only($formTypes)->values();

                $unit->assignedIndicators()->detach($inm->values()->diff($selected)->all());
                $unit->assignedIndicators()->syncWithoutDetaching($selected->all());
            }
        });
    }
}
