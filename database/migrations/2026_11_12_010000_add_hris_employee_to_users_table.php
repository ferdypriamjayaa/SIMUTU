<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links an account to the HRIS employee behind it. The identifier is the
     * opaque one EmployeeService derives from the HRIS NIP (HMAC with the app key),
     * so the HRIS identity itself is never stored here. One employee belongs to at most one
     * account. Existing columns are left alone: `nip` stays the login ID.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('hris_employee_id', 32)->nullable()->unique()->after('nip');
            $table->char('hris_company_code', 4)->nullable()->after('hris_employee_id');
            $table->timestamp('hris_linked_at')->nullable()->after('hris_company_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['hris_employee_id']);
            $table->dropColumn(['hris_employee_id', 'hris_company_code', 'hris_linked_at']);
        });
    }
};
