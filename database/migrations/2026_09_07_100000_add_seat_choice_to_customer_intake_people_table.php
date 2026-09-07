<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ที่นั่งที่ลูกค้าเลือกเองตอนกรอกข้อมูล — เป็น "ความตั้งใจ" ไม่ใช่การล็อก
     *
     * ที่นั่งจริงถูกกันให้ตอนแอดมินเปิดใบจองเท่านั้น (booking_seats + ล็อกใน Redis)
     * คนที่จองเองและจ่ายเงินเองในแอปจึงยังได้สิทธิ์ก่อนเสมอ คอลัมน์นี้ไม่มีดัชนี
     * unique โดยตั้งใจ — ถ้าที่นั่งถูกแย่งไป แอดมินต้องเห็นและตัดสินใจ ไม่ใช่ให้
     * ฐานข้อมูลปฏิเสธการกรอกข้อมูลของลูกค้าทิ้ง
     */
    public function up(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->string('seat_id', 10)->nullable()->after('pickup_point_id');
            // คันที่ที่นั่งนี้อยู่ (0 = รอบนี้มีรถคันเดียว) — กติกาเดียวกับ booking_seats
            $table->unsignedBigInteger('seat_vehicle_option_id')->default(0)->after('seat_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropColumn(['seat_id', 'seat_vehicle_option_id']);
        });
    }
};
