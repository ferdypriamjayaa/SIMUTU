<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dynamic indicator forms. Everything here is additive: existing tables
     * only gain one nullable column, and no existing row is changed.
     */
    public function up(): void
    {
        Schema::create('indicator_form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The database itself guarantees at most one published and one
            // draft version per indicator: these columns are NULL for every
            // other status, and NULLs never collide in a unique index.
            $table->unsignedBigInteger('published_indicator_id')->nullable()
                ->virtualAs("(case when `status` = 'published' then `indicator_id` end)");
            $table->unsignedBigInteger('draft_indicator_id')->nullable()
                ->virtualAs("(case when `status` = 'draft' then `indicator_id` end)");

            $table->unique(['indicator_id', 'version']);
            $table->unique('published_indicator_id');
            $table->unique('draft_indicator_id');
        });

        Schema::create('indicator_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_form_version_id')->constrained('indicator_form_versions')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->string('type', 20);
            $table->string('section', 150)->nullable();
            $table->string('placeholder')->nullable();
            $table->string('help_text', 500)->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_summary')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('default_value')->nullable();
            $table->decimal('min_value', 16, 4)->nullable();
            $table->decimal('max_value', 16, 4)->nullable();
            $table->boolean('allow_decimal')->default(false);
            $table->unsignedInteger('min_length')->nullable();
            $table->unsignedInteger('max_length')->nullable();
            $table->string('format', 30)->nullable();
            $table->string('max_field_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['indicator_form_version_id', 'key']);
            $table->index(['indicator_form_version_id', 'sort_order']);
        });

        Schema::create('indicator_field_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_field_id')->constrained('indicator_fields')->cascadeOnDelete();
            $table->string('value', 100);
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['indicator_field_id', 'value']);
        });

        Schema::table('observations', function (Blueprint $table) {
            // NULL = recorded before form versions existed.
            $table->foreignId('indicator_form_version_id')->nullable()->after('indicator_id')
                ->constrained('indicator_form_versions')->restrictOnDelete();
        });

        Schema::create('observation_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->cascadeOnDelete();
            // A field or option with recorded answers can never be deleted.
            $table->foreignId('indicator_field_id')->constrained('indicator_fields')->restrictOnDelete();
            $table->foreignId('indicator_field_option_id')->nullable()->constrained('indicator_field_options')->restrictOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            // One row per answer; checkbox fields have one row per ticked option.
            $table->unique(['observation_id', 'indicator_field_id', 'indicator_field_option_id'], 'observation_values_answer_unique');
        });
    }

    /**
     * Reverting fails while answers exist, which protects recorded data.
     */
    public function down(): void
    {
        Schema::drop('observation_values');

        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('indicator_form_version_id');
        });

        Schema::drop('indicator_field_options');
        Schema::drop('indicator_fields');
        Schema::drop('indicator_form_versions');
    }
};
