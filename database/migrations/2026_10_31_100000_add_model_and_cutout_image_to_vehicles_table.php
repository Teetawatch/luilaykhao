<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * การ์ด "วันเดินทาง" บนหน้าจอล็อกตอนรถกำลังมารับ — "9กข4835 · สีขาว · Honda HR-V"
 * พร้อมรูปรถพื้นใสวางคู่รูปคนขับ
 *
 * - model: ยี่ห้อ/รุ่นที่ลูกค้าใช้มองหารถคันจริงข้างถนน ("Toyota Commuter")
 *   คนละอย่างกับ name ซึ่งเป็นชื่อเรียกภายใน ("ตู้ VIP-01")
 * - cutout_image: รูปรถแบบตัดพื้นหลังออก (PNG โปร่งใส) — รูปใน images เป็นรูปถ่าย
 *   ภายในรถที่มีพื้นหลัง วางบนการ์ดสีเข้มแล้วกลายเป็นกรอบสี่เหลี่ยม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('model', 100)->nullable()->after('color');
            $table->string('cutout_image')->nullable()->after('images');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['model', 'cutout_image']);
        });
    }
};
