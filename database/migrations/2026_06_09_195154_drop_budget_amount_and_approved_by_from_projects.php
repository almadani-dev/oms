<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('budget_amount');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->decimal('budget_amount', 15, 2)->default(0)->after('project_status_id');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('notes');
        });
    }
};
