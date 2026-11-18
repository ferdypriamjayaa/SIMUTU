<?php

use App\Models\Indicator;
use App\Support\DefaultIndicatorForms;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Gives each existing INM indicator its current form as published
     * version 1, so data entry continues unchanged. Observations are not
     * touched: older records are read through this version by field key.
     */
    public function up(): void
    {
        Indicator::whereNotNull('form_type')->orderBy('id')->get()
            ->each(fn (Indicator $indicator) => DefaultIndicatorForms::install($indicator));
    }

    public function down(): void
    {
        // Forms may hold recorded answers by now; dropping the tables (previous
        // migration) is the only safe way back and refuses to run while they do.
    }
};
