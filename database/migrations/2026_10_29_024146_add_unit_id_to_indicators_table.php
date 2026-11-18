<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */

    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->foreignId('unit_id')
                ->nullable()
                ->after('indicator_category_id')
                ->constrained('units')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};
