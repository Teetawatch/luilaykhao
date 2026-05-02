<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->boolean('join_trip_enabled')->default(false)->after('installment_interval_days');
            $table->decimal('join_trip_price', 10, 2)->nullable()->after('join_trip_enabled');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('is_join_trip')->default(false)->after('promotion_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['join_trip_enabled', 'join_trip_price']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('is_join_trip');
        });
    }
};
