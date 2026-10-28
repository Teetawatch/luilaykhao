<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ข้อมูลที่อุทยานบังคับกรอกแต่เราไม่เก็บในการจอง (ภูมิลำเนา โทรศัพท์บ้าน) — ลูกค้า
 * กรอกตอนเซ็นแบบฟอร์ม เก็บเข้ารหัส (encrypted:array) และลบพร้อมลายเซ็นหลังจบทริป
 * ดู PermitFormFields::ASK_INPUTS
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->text('permit_extra')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn('permit_extra');
        });
    }
};
