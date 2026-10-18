<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The donor's own reference number for a project («رقم المشروع لدى الجهة
 * المانحة»): free text (leading zeros, letters, "/" or "-" kept as typed),
 * optional, not unique. Separate from donor_project_name (the donor-side
 * project NAME) and from the internal generated `code`; no existing value is
 * copied, moved or inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('donor_project_number')->nullable()->after('donor_project_name');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('donor_project_number');
        });
    }
};
