<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The public patient satisfaction survey is filled in without an account:
 * its observations have no user ("Publik (survei online)"). Existing rows
 * keep their user, and the foreign key to users stays in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('observations')->whereNull('user_id')->exists()) {
            throw new RuntimeException('Masih ada observasi survei publik tanpa pengguna; kolom user_id tidak dapat diwajibkan kembali.');
        }

        Schema::table('observations', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
