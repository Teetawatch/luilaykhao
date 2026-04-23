<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('pickup_point_id')->nullable()->after('pickup_region')->constrained('schedule_pickup_points')->nullOnDelete();
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->boolean('halal_food')->nullable()->after('allergies');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['pickup_point_id']);
            $table->dropColumn('pickup_point_id');
        });

        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn('halal_food');
        });
    }
};
