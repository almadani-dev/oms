<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standard Laravel database-notifications table (matches the stock
 * `notifications:table` stub). Required for Filament's panel
 * `->databaseNotifications()` and for `Illuminate\Notifications\Notifiable`
 * (already used by App\Models\User) to persist notifications instead of
 * only sending them over a transient channel. OMS Task 7B.2 is the first
 * feature to actually write to this table (background backup/verification
 * outcomes) — no prior task needed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
