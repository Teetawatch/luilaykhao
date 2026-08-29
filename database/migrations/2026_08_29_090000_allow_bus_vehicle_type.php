<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ทะเบียนยานพาหนะรับได้แค่รถตู้กับเรือ ทั้งที่รอบทริป (`trip_schedules.transport_type`)
     * รองรับรถบัสมาตั้งแต่ต้น — เลยลงทะเบียนรถบัสไว้เป็นคันไม่ได้สักที
     *
     * เปลี่ยนจาก ENUM เป็นสตริงธรรมดาด้วยเหตุผลเดียวกับที่ `transport_type` ย้ายมาก่อน
     * แล้ว: พาหนะแบบใหม่จะทยอยเพิ่มอีก และการเพิ่มค่าใน ENUM ต้อง ALTER ทั้งตาราง
     * บนโปรดักชันทุกครั้ง ค่าที่รับได้จริงคุมด้วย Vehicle::TYPES ในชั้น validation
     *
     * หมายเหตุ: sqlite (ฐานข้อมูลของเทสต์) บังคับ ENUM ผ่าน CHECK constraint จริง
     * จึงต้องเปลี่ยนทุก driver ไม่ใช่เฉพาะ MySQL
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('type', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->enum('type', ['van', 'boat'])->nullable(false)->change();
        });
    }
};
