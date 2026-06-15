<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_super', function (Blueprint $table) {
            $table->string('code_prefix', 20)->nullable()->after('name');
            $table->string('code', 50)->nullable()->unique()->after('code_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('projects_super', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code_prefix', 'code']);
        });
    }
};
