<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * โปรไฟล์แนะนำตัวของทีมงาน — ใช้ในการ์ด "แนะนำทีมงาน" ที่เด้งเข้าห้องแชทของรอบ
 * ตอนแอดมินมอบหมายสตาฟ (ชื่อเล่น/เบอร์/รูปใช้คอลัมน์เดิมของ users)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('staff_bio')->nullable();
            // ป่า/ยอดเขาที่เคยเดินเอง นอกเหนือจากทริปที่ระบบนับให้จากประวัติการคุมรอบ
            $table->json('staff_trails')->nullable();
            // ความถนัด / ใบรับรอง เช่น ปฐมพยาบาลเบื้องต้น ถ่ายรูป ทำอาหาร
            $table->json('staff_skills')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['staff_bio', 'staff_trails', 'staff_skills']);
        });
    }
};
