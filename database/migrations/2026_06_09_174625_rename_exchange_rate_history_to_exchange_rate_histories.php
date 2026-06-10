<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('exchange_rate_history', 'exchange_rate_histories');
    }

    public function down(): void
    {
        Schema::rename('exchange_rate_histories', 'exchange_rate_history');
    }
};
