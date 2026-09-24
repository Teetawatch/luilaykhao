<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชื่อที่รีวิวแสดง เมื่อไม่ใช่ชื่อบัญชีที่กดส่ง — แอดมินจองให้ลูกค้าจากบัญชี
 * ตัวเอง แล้วรีวิวแทนลูกค้าหลังจบทริป รีวิวต้องขึ้นชื่อลูกค้า ไม่ใช่ชื่อแอดมิน
 * null = ใช้ชื่อของ user ตามเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('reviewer_name')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('reviewer_name');
        });
    }
};
