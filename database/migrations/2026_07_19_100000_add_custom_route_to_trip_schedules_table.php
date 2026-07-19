<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เส้นทางเดินรถที่แอดมินวาดเอง (ลำดับพิกัด [{lat,lng},...]) — เมื่อกำหนดไว้
     * จะ override เส้นทางจาก Google Directions ในหน้าลูกค้าทั้งหมด
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->json('custom_route')->nullable()->after('photo_token');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('custom_route');
        });
    }
};
