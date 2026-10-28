<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * id ของหน้าชำระเงิน Beam (Payment Link) ที่ใบนี้ส่งลูกค้าไป
 *
 * ช่องทางบัตร / ผ่อนบัตร / e-Wallet / Apple Pay / Google Pay ไม่ได้ออกเป็น charge
 * ตรงๆ — Beam สร้าง charge ให้เองตอนลูกค้ากดจ่ายบนหน้าของเขา (ลองผิดได้หลายใบ
 * ภายในลิงก์เดียว) สิ่งเดียวที่เรารู้ตอนออกใบจึงเป็น id ของลิงก์ ส่วน
 * provider_charge_id ค่อยตามมาจาก webhook charge.succeeded (ต้องใช้ตอนคืนเงิน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_link_id')->nullable()->after('provider_charge_id');
            $table->index('provider_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider_link_id']);
            $table->dropColumn('provider_link_id');
        });
    }
};
