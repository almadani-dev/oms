<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_costs', function (Blueprint $table) {
            $table->dropColumn(['administrative_percentage', 'implementation_amount', 'received_amount']);
        });
    }

    public function down(): void
    {
        Schema::table('projects_costs', function (Blueprint $table) {
            $table->decimal('administrative_percentage', 5, 2)->default(0)->after('amount');
            $table->decimal('implementation_amount', 15, 2)->default(0)->after('administrative_percentage');
            $table->decimal('received_amount', 15, 2)->default(0)->after('implementation_amount');
        });
    }
};
