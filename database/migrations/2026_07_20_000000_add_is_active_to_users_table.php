<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No index: the users table is small (staff/admin accounts, not an
 * operational/transactional table), and every query filtering on
 * `is_active` in this task (active-Super-Admin detection/locking) also
 * always filters by role via a join, so a bare index here would not be
 * the one doing the work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
