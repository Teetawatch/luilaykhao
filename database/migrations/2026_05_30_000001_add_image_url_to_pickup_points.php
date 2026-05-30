<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule_pickup_points', 'image_url')) {
                $table->string('image_url', 500)->nullable()->after('map_url');
            }
        });

        Schema::table('vehicle_pickup_points', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicle_pickup_points', 'image_url')) {
                $table->string('image_url', 500)->nullable()->after('map_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_pickup_points', 'image_url')) {
                $table->dropColumn('image_url');
            }
        });

        Schema::table('vehicle_pickup_points', function (Blueprint $table) {
            if (Schema::hasColumn('vehicle_pickup_points', 'image_url')) {
                $table->dropColumn('image_url');
            }
        });
    }
};
