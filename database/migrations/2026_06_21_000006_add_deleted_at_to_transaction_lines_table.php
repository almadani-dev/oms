<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transaction_lines', 'deleted_at')) {
            Schema::table('transaction_lines', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transaction_lines', 'deleted_at')) {
            Schema::table('transaction_lines', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
