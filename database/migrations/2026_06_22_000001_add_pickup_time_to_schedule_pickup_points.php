<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule_pickup_points', 'pickup_time')) {
                // Structured departure time for this pickup point, stored as
                // "HH:MM" (24h). Separate from the free-text notes so the apps
                // can render it as a prominent time badge.
                $table->string('pickup_time', 5)->nullable()->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_pickup_points', 'pickup_time')) {
                $table->dropColumn('pickup_time');
            }
        });
    }
};
