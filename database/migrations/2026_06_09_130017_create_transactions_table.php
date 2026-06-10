<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('transaction_type_id')->constrained('transactions_types')->restrictOnDelete();
            $table->string('transaction_number')->unique();
            $table->dateTime('transaction_time');
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
