<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->foreignId('account_type_id')->nullable()->after('project_id')->constrained('accounts_type')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_type_id');
            $table->foreignId('account_id')->nullable()->after('project_id')->constrained('accounts')->nullOnDelete();
        });
    }
};
