<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // ระยะทางเดินป่ารวม (กม.) + ความสูงสะสมที่ต้องปีน (เมตร) — โชว์ในการ์ด Recap
            // เป็น optional; แอดมินกรอกเองต่อทริป
            $table->decimal('distance_km', 8, 2)->nullable()->after('duration_days');
            $table->unsignedInteger('elevation_gain_m')->nullable()->after('distance_km');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['distance_km', 'elevation_gain_m']);
        });
    }
};
