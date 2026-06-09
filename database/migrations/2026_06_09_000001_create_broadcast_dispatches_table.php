<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger of automatic marketing broadcasts already fired, so a given
        // event (a new trip, a low-seat round) is announced to everyone at most
        // once — even across retries or overlapping sweeps.
        Schema::create('broadcast_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');           // new_trip | low_seats | ...
            $table->string('dedupe_key')->unique();  // e.g. new_trip:42, low_seats:108
            $table->timestamp('created_at')->useCurrent();
        });

        // Per-user opt-out for automatic marketing pushes (default on so every
        // customer is reached unless they explicitly turn it off).
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('marketing_push_enabled')->default(true)->after('id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_dispatches');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marketing_push_enabled');
        });
    }
};
