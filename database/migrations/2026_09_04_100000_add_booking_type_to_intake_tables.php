<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * บอกให้ลิงก์เก็บข้อมูลรู้จัก "จอยทริป"
 *
 * การจองหนึ่งใบเป็นได้ประเภทเดียว (`bookings.is_join_trip`) กลุ่มที่กรอกเข้ามา
 * จึงต้องมีประเภทติดมาด้วยตั้งแต่ต้น ไม่งั้นแอดมินต้องกลับไปอ่านแชทว่าตกลง
 * คนกลุ่มนี้ขึ้นรถหรือขับไปเอง — และคนจอยจะถูกฟอร์มบังคับให้เลือกจุดขึ้นรถ
 * ทั้งที่ไม่มีรถให้ขึ้น
 *
 * ค่าเดิมของทุกแถวคือ 'normal' เพราะลิงก์ที่ออกไปแล้วทั้งหมดใช้กับการจองปกติ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_links', function (Blueprint $table) {
            // normal = ล็อกจองปกติ, join = ล็อกจอยทริป, ask = ให้ลูกค้าเลือกในฟอร์ม
            $table->string('booking_type', 10)->default('normal')->after('trip_schedule_id');
        });

        Schema::table('customer_intakes', function (Blueprint $table) {
            // ประเภทที่ตกลงกันแล้วจริง ๆ ของกลุ่มนี้ — มีได้แค่ normal หรือ join
            $table->string('booking_type', 10)->default('normal')->after('trip_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('intake_links', function (Blueprint $table) {
            $table->dropColumn('booking_type');
        });

        Schema::table('customer_intakes', function (Blueprint $table) {
            $table->dropColumn('booking_type');
        });
    }
};
