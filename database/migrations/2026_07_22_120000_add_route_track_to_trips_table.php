<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // เส้นทางจริงจากไฟล์ GPX ที่แอดมินอัปโหลด — เก็บทั้งจุดที่ลดรูปแล้ว
            // และค่าที่คำนวณไว้ล่วงหน้า (ระยะสะสม/ความสูง/ช่วงที่ชันที่สุด)
            // เพื่อไม่ต้องคำนวณซ้ำทุก request
            $table->json('route_track')->nullable()->after('elevation_gain_m');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('route_track');
        });
    }
};
