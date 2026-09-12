<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "ใบเดินทาง" — หน้าสาธารณะ /t/{token} ที่รวมกำหนดการ จุดขึ้นรถ รถ และเบอร์
 * ทีมงานของรอบไว้ที่เดียว เปิดได้โดยไม่ต้องล็อกอินและไม่ต้องมีแอป
 *
 * มีไว้เพราะลูกค้าที่ทีมงานเปิดใบจองแทนให้ส่วนมากไม่ได้โหลดแอป และครึ่งหนึ่ง
 * ไม่มีอีเมลจริงในระบบด้วย — ลิงก์เดียวที่ส่งได้ทั้งทางอีเมลและ SMS จึงเป็น
 * ช่องทางเดียวที่ครอบคลุมลูกค้าทุกคนได้จริง
 *
 * [brief_digest] เก็บลายนิ้วมือของเนื้อหาที่ส่งไปครั้งล่าสุด ถ้าทีมงานเปลี่ยนรถ
 * เปลี่ยนสตาฟ หรือขยับกำหนดการ ลายนิ้วมือจะเปลี่ยนตาม แล้วระบบจะส่งฉบับ
 * "อัปเดต" ให้ใหม่ — ไม่ใช่ส่งซ้ำทุกวันด้วยข้อมูลเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('brief_token', 24)->nullable()->unique()->after('passport_token');
            $table->timestamp('brief_sent_at')->nullable()->after('brief_token');
            $table->string('brief_digest', 40)->nullable()->after('brief_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['brief_token', 'brief_sent_at', 'brief_digest']);
        });
    }
};
