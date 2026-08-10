<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เดิมตารางนี้เก็บเฉพาะ "ค่าใช้จ่าย" ที่แอดมินคีย์เอง — เพิ่มฟิลด์ให้สตาฟ
        // บันทึกจากหน้างานได้ครบ: เป็นรายรับหรือรายจ่าย หมวดไหน ใช้วันไหน และรูปสลิป
        Schema::table('schedule_expenses', function (Blueprint $table) {
            // expense | income — แถวเก่าทั้งหมดคือรายจ่าย จึง default เป็น expense
            $table->string('kind', 16)->default('expense')->after('expense_template_id');
            $table->string('category', 32)->nullable()->after('kind');
            // สลิป/ใบเสร็จ อยู่บนดิสก์ private (เหมือนสลิปโอนเงิน) เปิดผ่าน signed URL
            $table->string('slip_path')->nullable()->after('note');
            // ทริปหลายวัน — วันที่จ่ายจริงไม่ใช่วันที่กดบันทึก
            $table->date('spent_at')->nullable()->after('slip_path');

            $table->index(['schedule_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_expenses', function (Blueprint $table) {
            $table->dropIndex(['schedule_id', 'kind']);
            $table->dropColumn(['kind', 'category', 'slip_path', 'spent_at']);
        });
    }
};
