<?php

use App\Support\ObservationForms;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links each INM indicator to its observation form, so forms no longer
     * depend on matching the indicator name at runtime.
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->string('form_type', 50)->nullable()->after('name')->index();
        });

        $inmCategoryId = DB::table('indicator_categories')->where('code', 'INM')->value('id');

        if ($inmCategoryId === null) {
            return;
        }

        foreach (ObservationForms::INM_INDICATORS as $name => $formType) {
            DB::table('indicators')
                ->where('indicator_category_id', $inmCategoryId)
                ->where('name', $name)
                ->update(['form_type' => $formType]);
        }
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropIndex(['form_type']);
            $table->dropColumn('form_type');
        });
    }
};
