<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * โน้ตของทีมงานตอนปิดเคส เคยถูกต่อท้ายลงฟิลด์ message เดียวกับข้อความที่
     * ลูกค้าพิมพ์ตอนขอความช่วยเหลือ ทำให้หลักฐานของลูกค้าปนกับบันทึกภายใน
     * และย้อนดูไม่ได้ว่าเดิมลูกค้าเขียนว่าอะไร
     */
    public function up(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table) {
            $table->text('admin_note')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table) {
            $table->dropColumn('admin_note');
        });
    }
};
