<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * บัญชี "เงา" — บัญชีที่ทีมงานสร้างแทนลูกค้าตอนเปิดใบจองให้ ลูกค้ายังไม่เคย
 * ตั้งรหัสผ่านเอง เดิมดูออกได้ทางเดียวคืออีเมลขึ้นต้นด้วย manual_ ซึ่งเป็นการ
 * เดาจากรูปแบบสตริง ไม่ใช่ข้อเท็จจริงที่บันทึกไว้
 *
 * claim_token คือลิงก์ "เปิดใช้บัญชี" ที่ส่งไปหาเบอร์ของลูกค้าเอง — การถือลิงก์
 * ที่ส่งเข้าเบอร์ตัวเองคือหลักฐานยืนยันตัวตนที่ดีที่สุดที่ระบบนี้มี เพราะยังไม่มี OTP
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_shadow')->default(false)->index()->after('password');
            $table->string('claim_token', 64)->nullable()->unique()->after('is_shadow');
            $table->timestamp('claim_token_sent_at')->nullable()->after('claim_token');
        });

        // บัญชีเงาที่มีอยู่แล้วก่อนหน้านี้ ถูกสร้างด้วยอีเมลปลอมรูปแบบเดียวเสมอ
        DB::table('users')
            ->where('email', 'like', 'manual%@luilaykhao.com')
            ->update(['is_shadow' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_shadow', 'claim_token', 'claim_token_sent_at']);
        });
    }
};
