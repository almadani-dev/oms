<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_cost_receipts', function (Blueprint $table) {
            // Denormalized currency of the receipt (mirrors the parent project cost).
            $table->foreignId('currency_id')
                ->nullable()
                ->after('amount')
                ->constrained('currencies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_cost_receipts', function (Blueprint $table) {
            $table->dropForeign(['currency_id']);
            $table->dropColumn('currency_id');
        });
    }
};
