<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เคยแจ้งทีมงานเรื่องกลุ่มนี้ไปแล้วหรือยัง
     *
     * ก่อนหน้านี้สัญญาณเดียวที่ทีมงานมีคือตัวเลขบนเมนูของหน้าแอดมิน ซึ่งเห็นได้
     * ต่อเมื่อเปิดหน้านั้นค้างไว้ ลูกค้ากรอกตอนสี่ทุ่มจึงไม่มีใครรู้จนเช้า —
     * ตรงข้ามกับเหตุผลที่ทำฟีเจอร์นี้ขึ้นมา (ตอบแชทให้เร็ว)
     *
     * เก็บเวลาไว้เพื่อให้แจ้งครั้งเดียวต่อกลุ่ม กลุ่ม 5 คนไม่ควรยิงเมล 5 ฉบับ
     */
    public function up(): void
    {
        Schema::table('customer_intakes', function (Blueprint $table) {
            $table->timestamp('team_notified_at')->nullable()->after('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intakes', function (Blueprint $table) {
            $table->dropColumn('team_notified_at');
        });
    }
};
