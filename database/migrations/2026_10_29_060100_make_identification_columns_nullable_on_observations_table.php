<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These columns were created for the identification form only. Other INM
     * forms store their own fields in `data`, so the columns become optional.
     * Existing identification records keep their values.
     */
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->string('officer_name')->nullable()->change();
            $table->string('patient_name')->nullable()->change();
            $table->boolean('medication_compliant')->nullable()->change();
            $table->boolean('procedure_compliant')->nullable()->change();
            $table->boolean('patient_name_verified')->nullable()->change();
            $table->boolean('birth_date_verified')->nullable()->change();
        });
    }

    /**
     * Reverting fails while non-identification observations exist, which
     * protects them from being silently altered.
     */
    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->string('officer_name')->nullable(false)->change();
            $table->string('patient_name')->nullable(false)->change();
            $table->boolean('medication_compliant')->nullable(false)->change();
            $table->boolean('procedure_compliant')->nullable(false)->change();
            $table->boolean('patient_name_verified')->nullable(false)->change();
            $table->boolean('birth_date_verified')->nullable(false)->change();
        });
    }
};
