<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เก็บเงินหน้างาน กันโกง — แจ้งโอนต้องแนบสลิป และสตาฟยืนยันยอดโอนได้เฉพาะคนที่มี
        // สลิป ส่วนเงินสดกดแยกและบันทึกวิธีรับไว้ (ใครรับอยู่ใน paid_marked_by_id อยู่แล้ว)
        Schema::table('chat_collection_dues', function (Blueprint $table) {
            // ไฟล์อยู่บนดิสก์ส่วนตัว (MediaDisk::slipDisk) ใต้ slips/ — สลิปเดียวกันอาจ
            // ผูกหลายแถว เมื่อคนจองจ่ายแทนทั้งกลุ่ม
            $table->string('slip_path')->nullable()->after('paid_claimed_at');
            // transfer | cash — null เมื่อยังไม่ได้ยืนยัน
            $table->string('paid_method', 10)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_collection_dues', function (Blueprint $table) {
            $table->dropColumn(['slip_path', 'paid_method']);
        });
    }
};
