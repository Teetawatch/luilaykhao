<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลิงก์เลือกรอบใหม่แบบไม่ต้องล็อกอิน (/reschedule/{token}) — ลูกค้าที่โทร/ทักไลน์
 * ให้ทีมงานจองให้อยู่ในบัญชีเงาที่ยังล็อกอินไม่ได้ ลิงก์ /my-bookings จึงพาไปทางตัน
 * แยกจาก payment_token เพราะเป็นสิทธิ์คนละเรื่อง (ย้ายรอบ ≠ จ่ายเงิน)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('reschedule_token', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique(['reschedule_token']);
            $table->dropColumn('reschedule_token');
        });
    }
};
