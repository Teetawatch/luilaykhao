<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('schedule_pickup_points')) {
            return; // Table doesn't exist, skip
        }
        
        // Check if the unique index exists before trying to drop it
        $indexes = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM schedule_pickup_points WHERE Key_name = 'schedule_pickup_points_schedule_id_region_unique'");
        if (!empty($indexes)) {
            Schema::table('schedule_pickup_points', function (Blueprint $table) {
                $table->dropUnique('schedule_pickup_points_schedule_id_region_unique');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('schedule_pickup_points')) {
            return; // Table doesn't exist, skip
        }
        
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->unique(['schedule_id', 'region']);
        });
    }
};
