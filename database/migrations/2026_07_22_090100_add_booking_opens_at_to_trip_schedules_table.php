<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เวลาเปิดจองสำหรับคนทั่วไป — สมาชิกระดับสูงจองได้ก่อนเวลานี้ตามชั่วโมงของระดับ
 *
 * ปล่อยว่างไว้ = เปิดให้ทุกคนจองได้ทันทีเหมือนเดิม รอบที่มีอยู่แล้วทั้งหมดจึงไม่
 * เปลี่ยนพฤติกรรม ต้องตั้งค่าเป็นรอบ ๆ ไปเมื่อจะใช้สิทธิ์จองก่อน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->timestamp('booking_opens_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('booking_opens_at');
        });
    }
};
