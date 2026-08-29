<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * รถที่ลูกค้าเลือกตอนจอง เก็บทั้งความสัมพันธ์และสำเนาชื่อ/ส่วนต่างราคา
     *
     * สำเนาไว้ด้วยเหตุผลเดียวกับ selected_addons: แอดมินแก้ราคาตัวเลือกหรือ
     * ลบตัวเลือกทิ้งทีหลังได้ แต่ใบจองที่จ่ายเงินไปแล้วต้องอธิบายยอดของตัวเอง
     * ได้ตลอดไป (ใบเสร็จ/ใบกำกับย้อนหลังอ่านจากคอลัมน์สำเนานี้)
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('vehicle_option_id')->nullable()->after('pickup_point_id')
                ->constrained('schedule_vehicle_options')->nullOnDelete();
            $table->string('vehicle_option_label', 60)->nullable()->after('vehicle_option_id');
            $table->decimal('vehicle_option_adjustment', 10, 2)->nullable()->after('vehicle_option_label');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['vehicle_option_id']);
            $table->dropColumn(['vehicle_option_id', 'vehicle_option_label', 'vehicle_option_adjustment']);
        });
    }
};
