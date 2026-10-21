<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รูปบัตรประชาชนหน้า/หลังที่ลูกค้าแนบมากับลิงก์รับข้อมูล (/r/ /g/) — ด่านใช้เป็น
 * หลักฐานทำ Border Pass ตอนนั้นยังไม่มีใบจองให้ผูก booking_documents จึงพักไว้ที่
 * ตัวคน แล้วผูกเข้าใบจองตอนเปิดจอง (BookingDocumentService::adoptIntakeIdFiles)
 *
 * ไฟล์อยู่บนดิสก์ส่วนตัวเดียวกับสลิป เปิดได้ผ่าน signed URL อายุสั้นเท่านั้น
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->string('border_pass_front_path')->nullable()->after('travel_document');
            $table->string('border_pass_back_path')->nullable()->after('border_pass_front_path');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropColumn(['border_pass_front_path', 'border_pass_back_path']);
        });
    }
};
