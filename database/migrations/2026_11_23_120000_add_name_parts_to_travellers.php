<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชื่อ / นามสกุล แยกช่อง (ไทย + อังกฤษตามพาสปอร์ต)
 *
 * `name` / `name_en` ยังเป็นชื่อเต็มเหมือนเดิม — ทุกที่ที่อ่านชื่ออยู่แล้วไม่ต้อง
 * แก้ ช่องใหม่มีไว้ให้รู้ว่า "ตรงไหนคือนามสกุล" จริง ๆ แทนการเดาจากช่องว่างแรก
 * (ชื่อต่างชาติหลายคำ เช่น Anna Maria Schmidt แยกผิดเสมอ) ว่าง = ใบเก่า/ช่องทาง
 * ที่ยังส่งชื่อช่องเดียว → HasNameParts แยกจากช่องว่างแรกเหมือนเดิม
 */
return new class extends Migration
{
    private const TABLES = ['booking_passengers', 'saved_travellers'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('first_name', 120)->nullable()->after('name');
                $table->string('last_name', 120)->nullable()->after('first_name');
                $table->string('first_name_en', 120)->nullable()->after('name_en');
                $table->string('last_name_en', 120)->nullable()->after('first_name_en');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['first_name', 'last_name', 'first_name_en', 'last_name_en']);
            });
        }
    }
};
