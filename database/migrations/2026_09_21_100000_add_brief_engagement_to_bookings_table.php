<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ลูกค้าเปิดใบเดินทางหรือยัง" — สองคอลัมน์ที่เปลี่ยนการโทรตามทั้งรอบ ให้เหลือ
 * การโทรตามเฉพาะคนที่ยังไม่เห็น
 *
 * [brief_read_at] ระบบบันทึกเองตอนลิงก์ถูกเปิดครั้งแรก — บอกได้แค่ว่า "ลิงก์ถูกเปิด"
 * ซึ่งอาจเป็นคนที่บ้านที่ลูกค้าส่งต่อให้ก็ได้
 * [brief_ack_at] ลูกค้ากดปุ่มเอง — เป็นคำตอบจากคนจริงว่าอ่านแล้วและรับทราบ
 *
 * เก็บแยกกันเพราะสองอย่างนี้ตอบคนละคำถาม และทีมงานต้องแยกออกว่ากำลังดูอันไหนอยู่
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('brief_read_at')->nullable()->after('brief_digest');
            $table->timestamp('brief_ack_at')->nullable()->after('brief_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['brief_read_at', 'brief_ack_at']);
        });
    }
};
