<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลิงก์ตามเก็บเอกสารเดินทางของทั้งการจอง
 *
 * แอปรุ่นที่ลูกค้าติดตั้งอยู่ก่อนหน้ายังไม่มีช่องกรอกพาสปอร์ต การจองทริป
 * ต่างประเทศจากแอปพวกนั้นจึงเข้ามาแบบไม่มีเอกสาร โทเคนนี้คือทางกลับมากรอก
 * ทีหลังผ่านหน้าเว็บ โดยคนจองกรอกให้ผู้เดินทางทุกคนในการจองได้ในหน้าเดียว
 * (รูปแบบเดียวกับ birthdate_token ที่ใช้ตามเก็บวันเกิด)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('passport_token', 32)->nullable()->unique()->after('birthdate_token');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('passport_token');
        });
    }
};
