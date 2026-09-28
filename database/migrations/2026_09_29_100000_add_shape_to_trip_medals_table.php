<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทรงเหรียญที่เจ้าของเลือกตอนแชร์ — null = แบบของทริปเอง (ภาพออกแบบเองถ้ามี
 * ไม่งั้นขอบหยัก) ค่าที่ใช้ได้อยู่ใน MedalGeometry::SHAPES
 *
 * เป็นของเหรียญใบนั้น ไม่ใช่ของทริป: ลิงก์ /m/{token} และภาพ OG ต้องหน้าตา
 * ตรงกับการ์ดที่เจ้าของโพสต์ไป
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_medals', function (Blueprint $table) {
            $table->string('shape', 16)->nullable()->after('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('trip_medals', function (Blueprint $table) {
            $table->dropColumn('shape');
        });
    }
};
