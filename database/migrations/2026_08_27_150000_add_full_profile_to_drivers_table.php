<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทะเบียนคนขับเก็บข้อมูลคนขับให้ครบในที่เดียว — หน้ายานพาหนะจะได้แค่เลือกชื่อ
 * แล้วดึงทุกอย่างมาใช้ ไม่ต้องกรอกซ้ำรายคัน
 *
 * `pin_user_id` ย้าย "รหัสส่ง GPS" จากรถมาอยู่กับคน: คนขับหนึ่งคนมีบัญชีเดียว
 * ตั้ง PIN ครั้งเดียว ใช้ได้กับรถทุกคันที่เขาขับ (เดิมสร้างบัญชีใหม่ทุกคัน และ
 * PIN ห้ามซ้ำกัน คนขับที่ขับสามคันจึงต้องจำสามรหัส)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            // ใบขับขี่
            $table->string('license_type', 50)->nullable()->after('license_number');
            $table->date('license_expires_at')->nullable()->after('license_type');
            $table->string('license_photo', 500)->nullable()->after('license_expires_at');

            // ตัวตนและการติดต่อ (id_card เข้ารหัสที่ระดับโมเดล จึงต้องเป็น text)
            $table->text('id_card')->nullable()->after('license_photo');
            $table->date('birth_date')->nullable()->after('id_card');
            $table->text('address')->nullable()->after('birth_date');
            $table->string('line_id', 100)->nullable()->after('address');
            $table->string('emergency_contact', 100)->nullable()->after('line_id');
            $table->string('emergency_phone', 20)->nullable()->after('emergency_contact');

            // บัญชีสำหรับล็อกอินส่ง GPS ของคนขับคนนี้
            $table->foreignId('pin_user_id')->nullable()->after('is_active')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pin_user_id');
            $table->dropColumn([
                'license_type', 'license_expires_at', 'license_photo',
                'id_card', 'birth_date', 'address', 'line_id',
                'emergency_contact', 'emergency_phone',
            ]);
        });
    }
};
