<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            // Every existing row was written to the public disk (see the OMS
            // Task 6 audit) - default 'public' preserves that fact for rows
            // already in the table without a backfill statement. New rows
            // created after Task 6B's Resource cutover will explicitly write
            // 'attachments' (the private disk added in Task 6A).
            $table->string('disk', 32)->default('public')->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
    }
};
