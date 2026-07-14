<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('exchange_rate_history')
            && ! Schema::hasTable('exchange_rate_histories')
        ) {
            Schema::rename(
                'exchange_rate_history',
                'exchange_rate_histories'
            );
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('exchange_rate_histories')
            && ! Schema::hasTable('exchange_rate_history')
        ) {
            Schema::rename(
                'exchange_rate_histories',
                'exchange_rate_history'
            );
        }
    }
};
