<?php

namespace Tests\Concerns;

use App\Models\Indicator;
use App\Models\IndicatorCategory;
use App\Models\Unit;
use App\Support\DefaultIndicatorForms;
use App\Support\ObservationForms;
use Illuminate\Support\Facades\DB;

trait CreatesInmIndicators
{
    /**
     * @return array<string, Indicator> keyed by form type
     */
    protected function createInmIndicators(): array
    {
        $categoryId = DB::table('indicator_categories')->insertGetId([
            'code' => 'INM',
            'slug' => 'inm',
            'name' => 'Indikator Nasional Mutu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $indicators = [];

        foreach (ObservationForms::INM_INDICATORS as $name => $formType) {
            $indicator = Indicator::create([
                'indicator_category_id' => $categoryId,
                'name' => $name,
                'is_active' => true,
            ]);
            $indicator->forceFill(['form_type' => $formType])->save();

            // Same step the migration performs for existing indicators.
            DefaultIndicatorForms::install($indicator);

            $indicators[$formType] = $indicator;
        }

        return $indicators;
    }

    /**
     * Assign the given INM indicators (by form type) to a unit.
     *
     * @param  array<string, Indicator>  $indicators
     * @param  list<string>|null  $formTypes  null assigns all of them
     */
    protected function assignIndicators(Unit $unit, array $indicators, ?array $formTypes = null): void
    {
        $selected = $formTypes === null ? $indicators : array_intersect_key($indicators, array_flip($formTypes));

        $unit->assignedIndicators()->syncWithoutDetaching(array_map(fn (Indicator $i) => $i->id, array_values($selected)));
    }

    protected function inmCategory(): IndicatorCategory
    {
        return IndicatorCategory::where('code', 'INM')->firstOrFail();
    }
}
