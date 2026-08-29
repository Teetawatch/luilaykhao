<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * จุดขึ้นรถของผู้เดินทางแต่ละคน
     *
     * กลุ่มเดียวกันขึ้นคนละจุดเป็นเรื่องปกติ และคนที่รู้ว่าตัวเองขึ้นที่ไหนคือ
     * เจ้าตัว ไม่ใช่คนที่กดลิงก์มาก่อน — ถามตอนกรอกจึงได้คำตอบที่ถูกกว่ามาไล่ถาม
     * ในแชททีหลัง ช่องนี้ตรงกับ `booking_passengers.pickup_point_id` เพื่อให้ตอน
     * ดึงไปเปิดการจองเป็นการคัดลอกค่าตรง ๆ เหมือนช่องอื่นทั้งชุด
     */
    public function up(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->foreignId('pickup_point_id')
                ->nullable()
                ->after('is_lead')
                ->constrained('schedule_pickup_points')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_point_id');
        });
    }
};
