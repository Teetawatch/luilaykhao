<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ภาษาที่ลูกค้าเลือกในแอป — ใช้กับข้อความที่ส่งตอนแอปไม่ได้เปิด (push, LINE,
 * การ์ดวันเดินทาง) ซึ่งไม่มี request ให้อ่าน header
 *
 * null = ไม่เคยเลือก = ภาษาไทย (ลูกค้าเว็บ/LIFF และแอปรุ่นก่อนที่ส่งภาษามา)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
