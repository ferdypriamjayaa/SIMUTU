<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conditional fields: a field can be shown only when a single-choice field
 * of the same form version has one of the given option values (e.g. the
 * "Rawat Inap" questions of the patient survey). Both columns stay null for
 * a field that is always shown, so existing forms are unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('indicator_fields', function (Blueprint $table) {
            $table->string('visible_when_key', 64)->nullable()->after('max_field_key');
            $table->json('visible_when_values')->nullable()->after('visible_when_key');
        });
    }

    public function down(): void
    {
        Schema::table('indicator_fields', function (Blueprint $table) {
            $table->dropColumn(['visible_when_key', 'visible_when_values']);
        });
    }
};
