<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * จำนวนทริปสะสมของแต่ละคน — ตัวเลขที่ใช้ตัดสินระดับสมาชิกตั้งแต่นี้ไป
 * (แต้มยังเก็บไว้เหมือนเดิม แต่มีไว้แลกของรางวัลอย่างเดียว)
 *
 * คอลัมน์ตั้งต้นเป็น 0 ทุกคน แล้วให้ `php artisan loyalty:backfill` เป็นตัวไล่
 * นับย้อนหลังจากการจองจริง — ไม่ทำใน migration เพราะเป็นการคำนวณที่ต้องรันซ้ำ
 * ได้และควรดูผลก่อนด้วย --dry-run
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_accounts', function (Blueprint $table) {
            $table->unsignedInteger('lifetime_trips')->default(0)->after('lifetime_points');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_accounts', function (Blueprint $table) {
            $table->dropColumn('lifetime_trips');
        });
    }
};
