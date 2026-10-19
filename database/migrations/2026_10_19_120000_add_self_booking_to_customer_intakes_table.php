<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ลูกค้ากดจองและจ่ายเงินเองจากลิงก์กลุ่มได้แล้ว ไม่ต้องรอทีมงานส่ง QR
     *
     * self_booked_at       ใบจองล่าสุดของกลุ่มนี้ลูกค้าเปิดเอง (ไม่ใช่ทีมงานดึงไปจอง)
     *                      หน้ากลุ่มใช้เลือกคำพูด และหน้าแอดมินใช้บอกที่มา
     * self_booking_attempts กดจองไปแล้วกี่ครั้ง — ลิงก์กลุ่มอยู่ในแชท ใครก็กดได้
     *                      คนที่กดจองเล่นแล้วไม่จ่ายซ้ำ ๆ จะกันที่นั่งคนอื่นไว้ครั้งละ
     *                      สิบนาทีได้ไม่รู้จบ ถ้าไม่มีเพดาน
     */
    public function up(): void
    {
        Schema::table('customer_intakes', function (Blueprint $table) {
            $table->timestamp('self_booked_at')->nullable()->after('converted_at');
            $table->unsignedSmallInteger('self_booking_attempts')->default(0)->after('self_booked_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intakes', function (Blueprint $table) {
            $table->dropColumn(['self_booked_at', 'self_booking_attempts']);
        });
    }
};
