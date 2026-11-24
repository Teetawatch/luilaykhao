<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แจกคูปองแบบจำนวนจำกัด ("แจก 100 ใบ ให้ลูกค้าเก็บไว้ใช้")
 *
 * เดิมมีแค่ max_uses ที่นับตอนจอง — คนกด "เก็บ" ได้ไม่จำกัด แล้วคนที่จองก่อน 100
 * คนกินโควตาไปหมด คนที่เก็บไว้ที่เหลือเปิดมาเจอคูปองใช้ไม่ได้ และคนเดียวใช้โค้ด
 * เดิมซ้ำได้ทุกใบจอง
 *
 * claim_limit   จำนวนใบที่แจก (null = ไม่จำกัด แบบเดิม) — เก็บครบแล้วคนอื่นเก็บไม่ได้
 *               ตั้งค่าแล้วเฉพาะคนที่ถือใบอยู่ใช้ได้ (กรอกโค้ดตอนจองโดยยังไม่ได้เก็บ =
 *               เก็บให้อัตโนมัติถ้ายังเหลือ)
 * claimed_count ใบที่ถูกเก็บไปแล้ว — เพิ่มใต้ lockForUpdate ไม่แจกเกิน
 * per_user_limit ใช้ได้กี่ครั้งต่อบัญชี (null = ไม่จำกัด) นับจากใบจองที่ไม่ถูกยกเลิก
 *
 * saved_promotions.claimed_at = แถวนี้ถือใบจากโควตาแล้ว — แถวที่เก็บไว้ก่อนแอดมิน
 * ตั้งจำนวนใบไม่นับว่าถือใบ (ยังไม่ได้กินโควตา)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->unsignedInteger('claim_limit')->nullable()->after('used_count');
            $table->unsignedInteger('claimed_count')->default(0)->after('claim_limit');
            $table->unsignedInteger('per_user_limit')->nullable()->after('claimed_count');
        });

        Schema::table('saved_promotions', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('saved_promotions', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['claim_limit', 'claimed_count', 'per_user_limit']);
        });
    }
};
