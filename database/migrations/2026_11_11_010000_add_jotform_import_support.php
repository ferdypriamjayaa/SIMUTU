<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support for importing form definitions exported from Jotform.
     * Additive only: one nullable column and one new table.
     */
    public function up(): void
    {
        Schema::table('indicator_fields', function (Blueprint $table) {
            // Identifier of the question in the source system (Jotform question
            // ID, or "qid:row" for one row of a matrix question).
            $table->string('external_id', 64)->nullable()->after('max_field_key');
            $table->index(['indicator_form_version_id', 'external_id']);
        });

        Schema::create('jotform_forms', function (Blueprint $table) {
            $table->id();
            // The Jotform Form ID is the identity of a form across imports.
            $table->string('jotform_form_id', 32)->unique();
            $table->string('title');
            $table->string('source_directory')->nullable();
            // One Jotform form maps to one indicator, and the other way round.
            $table->foreignId('indicator_id')->nullable()->unique()->constrained('indicators')->nullOnDelete();
            // The form version produced by the last import (NULL if discarded).
            $table->foreignId('indicator_form_version_id')->nullable()->constrained('indicator_form_versions')->nullOnDelete();
            $table->char('definition_hash', 64)->nullable();
            $table->unsignedInteger('question_count')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jotform_forms');

        Schema::table('indicator_fields', function (Blueprint $table) {
            $table->dropIndex(['indicator_form_version_id', 'external_id']);
            $table->dropColumn('external_id');
        });
    }
};
