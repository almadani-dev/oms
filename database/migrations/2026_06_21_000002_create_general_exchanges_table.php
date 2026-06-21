<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_exchanges', function (Blueprint $table) {
            $table->id();
            // constrained() already creates an index on each foreign key.
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->decimal('original_amount', 15, 2);
            $table->decimal('administrative_percentage', 5, 2)->default(0);
            $table->decimal('transfer_percentage', 5, 2)->default(0);
            $table->decimal('fx_rate', 15, 6)->default(1);
            $table->decimal('final_amount', 15, 2);
            $table->foreignId('source_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('disbursement_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_exchanges');
    }
};
