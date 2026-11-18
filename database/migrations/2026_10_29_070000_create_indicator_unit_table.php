<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * INM indicators assigned to each unit. Only configuration lives here:
     * observations reference indicators directly, so changing assignments
     * never touches historical data.
     */
    public function up(): void
    {
        Schema::create('indicator_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['indicator_id', 'unit_id']);
        });

        // Before this table existed every unit could record every active INM
        // indicator. Keep that for existing units so current PIC Unit accounts
        // are not locked out; admins can narrow it per unit afterwards.
        $indicatorIds = DB::table('indicators')
            ->join('indicator_categories', 'indicator_categories.id', '=', 'indicators.indicator_category_id')
            ->where('indicator_categories.code', 'INM')
            ->whereNotNull('indicators.form_type')
            ->where('indicators.is_active', true)
            ->pluck('indicators.id');

        $now = now();
        $rows = [];

        foreach (DB::table('units')->pluck('id') as $unitId) {
            foreach ($indicatorIds as $indicatorId) {
                $rows[] = [
                    'indicator_id' => $indicatorId,
                    'unit_id' => $unitId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('indicator_unit')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_unit');
    }
};
