<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * วันหยุดราชการ — ใช้หา "หยุดยาว" ให้ลูกค้า (LongWeekendService)
 *
 * แหล่งหลักคือปฏิทินวันหยุดไทยของ Google (HolidayCalendarService ดึงทุกสัปดาห์)
 * ซึ่งรวมวันหยุดชดเชยและวันหยุดพิเศษที่ ครม. ประกาศเพิ่มให้แล้ว แอดมินเพิ่มเอง
 * (source = manual) หรือปิดบางวัน (is_active = false) ได้ การดึงรอบถัดไปไม่ทับ
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('public_holidays')) {
            return;
        }

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            // ชื่อสั้นที่ลูกค้าเห็น เช่น "วันพ่อแห่งชาติ" — แอดมินแก้ได้
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            // ชื่อเต็มตามประกาศ (จากปฏิทิน)
            $table->string('official_name', 255)->nullable();
            // google = ดึงอัตโนมัติ · manual = แอดมินเพิ่มเอง
            $table->string('source', 10)->default('manual');
            $table->string('external_id', 120)->nullable();
            $table->boolean('is_active')->default(true);
            // แอดมินแก้ชื่อเอง — การดึงรอบถัดไปไม่ทับชื่อ
            $table->boolean('name_locked')->default(false);
            $table->timestamps();

            $table->index('date');
            $table->unique(['source', 'external_id'], 'public_holidays_source_external_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
