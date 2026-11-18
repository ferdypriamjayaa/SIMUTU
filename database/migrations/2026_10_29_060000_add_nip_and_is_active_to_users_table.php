<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NIP is nullable so existing accounts survive the migration. Assign NIP
     * values with `php artisan simutu:assign-nip` before those users log in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nip', 30)->nullable()->unique()->after('unit_id');
            $table->boolean('is_active')->default(true)->after('role');

            // Email is account information only and no longer needed to log in.
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'is_active']);
            $table->string('email')->nullable(false)->change();
        });
    }
};
