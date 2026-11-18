<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which HRIS division/department placements belong to which SIMUTU unit.
     * Set by Admin; never guessed. Holds organisation names only, no
     * employee data.
     */
    public function up(): void
    {
        Schema::create('hris_unit_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->string('company_code', 10);
            // Exactly as HRIS returns them; NULL = not set in HRIS.
            $table->string('division')->nullable();
            $table->string('department')->nullable();
            $table->timestamps();

            // NULL never collides in a unique index, so the pair is folded
            // into one non-null key for the duplicate check.
            $table->string('placement_key', 520)->storedAs("concat(ifnull(`division`, ''), '\\n', ifnull(`department`, ''))");
            $table->unique(['unit_id', 'company_code', 'placement_key'], 'hris_unit_mappings_unique');
            $table->index(['company_code', 'placement_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hris_unit_mappings');
    }
};
