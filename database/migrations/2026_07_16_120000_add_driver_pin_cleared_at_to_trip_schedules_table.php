<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            // ทำเครื่องหมายว่ารอบนี้ล้างรหัส PIN ของคนขับไปแล้ว เพื่อไม่ให้ job รายวัน
            // ย้อนกลับมาล้าง PIN ใหม่ที่แอดมินตั้งไว้สำหรับรอบถัดไปของรถคันเดียวกัน
            $table->timestamp('driver_pin_cleared_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('driver_pin_cleared_at');
        });
    }
};
