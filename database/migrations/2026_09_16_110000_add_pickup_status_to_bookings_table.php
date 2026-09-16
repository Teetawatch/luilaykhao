<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ลูกค้าบอกสถานะตัวเองที่จุดนัด" — กำลังไป / ถึงแล้ว / อาจจะสาย
 *
 * เช้าวันเดินทาง สตาฟยืนอยู่ที่จุดรับแล้วต้องรู้อย่างเดียวว่า "ครบหรือยัง"
 * ทางเดียวที่มีตอนนี้คือไล่โทรทีละคน ซึ่งกินเวลาที่ควรใช้จัดของขึ้นรถ และ
 * ลูกค้าที่ยังติดไฟแดงก็ไม่มีทางบอกเราได้นอกจากโทรกลับมาหาเบอร์ที่ไม่ว่าง
 *
 * เก็บเป็นสถานะระดับ "ใบจอง" ไม่ใช่รายคน เพราะคนที่มาด้วยกันในใบเดียวมา
 * ด้วยรถคันเดียวกัน — และคนที่กดคือคนจอง
 *
 * [pickup_status_eta_minutes] มีค่าเฉพาะตอนแจ้งว่าสาย เพราะ "สายเท่าไหร่"
 * คือข้อมูลเดียวที่ทำให้สตาฟตัดสินใจได้ว่าจะรอหรือจะให้ตามไปขึ้นจุดถัดไป
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('pickup_status', 20)->nullable()->after('checked_in_at');
            $table->timestamp('pickup_status_at')->nullable()->after('pickup_status');
            $table->unsignedSmallInteger('pickup_status_eta_minutes')->nullable()->after('pickup_status_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['pickup_status', 'pickup_status_at', 'pickup_status_eta_minutes']);
        });
    }
};
