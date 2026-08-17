<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ทริปต่างประเทศบินไป ไม่ได้นั่งรถตู้ไป — `transport_type` จึงต้องรับค่า
     * 'flight' ได้ด้วย
     *
     * เปลี่ยนจาก ENUM เป็นสตริงธรรมดาแทนการต่อค่าเข้า ENUM เดิม เพราะพาหนะแบบใหม่
     * (เครื่องบิน รถไฟ เรือเฟอร์รี่) จะทยอยเพิ่มอีก และทุกครั้งที่เพิ่มค่าใน ENUM
     * ต้อง ALTER ตารางทั้งใบบนโปรดักชัน ตัวคุมค่าที่รับได้จริงคือ validation rule
     * ในชั้น request (TripSchedule::TRANSPORT_TYPES) เหมือนที่ `trips.type`
     * เคยย้ายมาก่อนหน้านี้
     *
     * หมายเหตุ: sqlite (ฐานข้อมูลของเทสต์) บังคับ ENUM ผ่าน CHECK constraint จริง
     * จึงต้องเปลี่ยนทุก driver ไม่ใช่เฉพาะ MySQL
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->string('transport_type', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->enum('transport_type', ['van', 'boat', 'bus'])->nullable(false)->change();
        });
    }
};
