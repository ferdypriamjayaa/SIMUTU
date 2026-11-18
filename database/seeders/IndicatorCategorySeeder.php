<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class IndicatorCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('indicator_categories')->insert([
            [
                'code' => 'INM',
                'slug' => 'inm',
                'name' => 'Indikator Nasional Mutu',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'IMP_RS',
                'slug' => 'imp-rs',
                'name' => 'Indikator Mutu Pelayanan Rumah Sakit',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'IMP_UNIT',
                'slug' => 'imp-unit',
                'name' => 'Indikator Mutu Pelayanan Unit',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
