<?php

namespace Database\Seeders;

use App\Models\Indicator;
use App\Support\DefaultIndicatorForms;
use App\Support\ObservationForms;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IndicatorSeeder extends Seeder
{
    public function run(): void
    {
        $inmCategory = DB::table('indicator_categories')
            ->where('code', 'INM')
            ->first();

        if ($inmCategory === null) {
            throw new RuntimeException(
                'Kategori INM belum tersedia di database.'
            );
        }

        foreach (ObservationForms::INM_INDICATORS as $indicatorName => $formType) {
            DB::table('indicators')->updateOrInsert(
                [
                    'indicator_category_id' => $inmCategory->id,
                    'name' => $indicatorName,
                ],
                [
                    'form_type' => $formType,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        // Each INM indicator starts with its original form as published version 1.
        Indicator::where('indicator_category_id', $inmCategory->id)
            ->whereNotNull('form_type')
            ->get()
            ->each(fn (Indicator $indicator) => DefaultIndicatorForms::install($indicator));
    }
}
