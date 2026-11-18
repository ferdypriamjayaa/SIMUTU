<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UpdateIndicatorCategorySlugSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            'INM' => 'inm',
            'IMP_RS' => 'imp-rs',
            'IMP_UNIT' => 'imp-unit',
        ];

        foreach ($categories as $code => $slug) {
            DB::table('indicator_categories')
                ->where('code', $code)
                ->update([
                    'slug' => $slug,
                    'updated_at' => now(),
                ]);
        }
    }
}
