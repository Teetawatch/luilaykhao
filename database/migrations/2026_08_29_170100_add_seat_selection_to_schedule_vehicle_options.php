<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * คันนี้ให้ลูกค้าเลือกที่นั่งเองไหม
     *
     * ตอนนี้ทุกคันมีผังของตัวเองได้แล้ว (ที่นั่งผูกกับคันใน booking_seats) แต่บาง
     * คันเจ้าของรอบอยากจัดที่นั่งหน้างานเอง เช่น รถบัสที่ต้องนั่งตามกลุ่ม — ปิด
     * สวิตช์นี้แล้วคันนั้นจะข้ามขั้นเลือกที่นั่งไปเลย
     */
    public function up(): void
    {
        Schema::table('schedule_vehicle_options', function (Blueprint $table) {
            $table->boolean('seat_selection')->default(true)->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_vehicle_options', function (Blueprint $table) {
            $table->dropColumn('seat_selection');
        });
    }
};
