<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * โทเคนของ "การ์ดนับถอยหลัง" ที่ลูกค้าแชร์ลงสตอรี่/ฟีด
 *
 * จงใจแยกจาก share_token ที่มีอยู่แล้ว — share_token เปิดหน้าติดตามรถแบบสด
 * ซึ่งเป็นตำแหน่งจริงของผู้โดยสาร ส่วนโทเคนนี้ถูกโพสต์ให้คนทั้งอินเทอร์เน็ตเห็น
 * เอามาใช้ร่วมกันไม่ได้เด็ดขาด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('story_token', 16)->nullable()->unique()->after('share_token');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('story_token');
        });
    }
};
