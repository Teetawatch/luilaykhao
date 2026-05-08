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
        
        $indexExists = collect(Schema::getIndexes('schedule_pickup_points'))
            ->contains(fn (array $index) => $index['name'] === 'schedule_pickup_points_schedule_id_region_unique');

        if ($indexExists) {
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
