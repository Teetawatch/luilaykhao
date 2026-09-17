<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * อุปกรณ์ที่ลูกค้าเลือกเช่าตอนกรอกข้อมูล — เก็บรายคน ไม่ใช่รายกลุ่ม
     *
     * เพราะฟอร์มนี้เป็นของ "หนึ่งคน" เสมอ (เพื่อนแต่ละคนกดลิงก์กลุ่มมากรอกของ
     * ตัวเองคนละเวลา) คนที่ไม่มีถุงนอนกับคนที่มีของตัวเองอยู่ในกลุ่มเดียวกัน
     * แอดมินรวมเป็นยอดของใบจองเดียวตอนดึงไปเปิดจอง
     *
     * แช่เป็น snapshot {key, name, unit_price, quantity, total_price, image_url}
     * รูปแบบเดียวกับ `bookings.selected_rentals` — คัดลอกไปเปิดใบจองได้ตรง ๆ
     * และราคาที่ลูกค้าเห็นตอนกรอกยังอ่านย้อนได้แม้แคตตาล็อกจะถูกแก้ทีหลัง
     */
    public function up(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->json('selected_rentals')->nullable()->after('seat_vehicle_option_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropColumn('selected_rentals');
        });
    }
};
