<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions_types', function (Blueprint $table) {
            $table->foreignId('transaction_super_type_id')
                ->nullable()
                ->after('id')
                ->constrained('transaction_super_types')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions_types', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\TransactionSuperType::class);
            $table->dropColumn('transaction_super_type_id');
        });
    }
};
