<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each traveller (the booking owner or an accepted companion) may review a
 * booking once. Previously reviews were one-per-booking at the application
 * layer; now they are one-per-(booking, user), so guard that at the DB level
 * too. Existing data already satisfies this (at most one review per booking).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unique(['booking_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'user_id']);
        });
    }
};
