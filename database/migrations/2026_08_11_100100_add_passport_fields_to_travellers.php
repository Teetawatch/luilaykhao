<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ข้อมูลที่ต้องใช้ออกตั๋วเครื่องบินของทริปต่างประเทศ
 *
 * เก็บทั้งสามที่ให้เหมือนกัน (ผู้โดยสารในบุ๊กกิ้ง / สมุดผู้ร่วมเดินทาง / โปรไฟล์)
 * เพื่อให้ "เติมให้อัตโนมัติ" ทำงานได้เหมือนชุดข้อมูลเดิม
 *
 * `passport_no` เป็น text เพราะเข้ารหัสระดับคอลัมน์เหมือน `id_card` — ค่าที่
 * เก็บจริงยาวกว่าเลขพาสปอร์ตมาก
 */
return new class extends Migration
{
    /** ตารางที่ต้องมีชุดฟิลด์เดียวกัน */
    private const TABLES = ['booking_passengers', 'saved_travellers', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // ชื่อ-สกุลภาษาอังกฤษต้องสะกดตรงหน้าพาสปอร์ต ไม่งั้นขึ้นเครื่องไม่ได้
                $table->string('name_en')->nullable();
                $table->string('nationality', 2)->nullable();
                $table->text('passport_no')->nullable();
                $table->date('passport_expires_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['name_en', 'nationality', 'passport_no', 'passport_expires_at']);
            });
        }
    }
};
