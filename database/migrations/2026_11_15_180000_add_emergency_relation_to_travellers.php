<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ความสัมพันธ์ของผู้ติดต่อฉุกเฉิน (แม่ / สามี / เพื่อน ...) แยกจากชื่อ
 *
 * เดิมมีแค่ `emergency_contact` ช่องเดียว ลูกค้าบางคนใส่ชื่อ บางคนใส่ "คุณแม่" บางคน
 * ใส่ทั้งคู่ — แบบฟอร์มลงทะเบียนอุทยานถามแยกกัน จึงต้องเก็บแยก ใบจองเก่าที่ไม่มีค่านี้
 * เดาจากข้อความเดิมตอนส่งออก (App\Support\EmergencyRelation::guess)
 *
 * เก็บทุกที่ที่มีผู้ติดต่อฉุกเฉิน เพื่อให้ "เติมให้อัตโนมัติ" พาค่าไปด้วย
 */
return new class extends Migration
{
    private const TABLES = ['booking_passengers', 'saved_travellers', 'users', 'customer_intake_people'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('emergency_relation', 50)->nullable()->after('emergency_contact');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('emergency_relation');
            });
        }
    }
};
