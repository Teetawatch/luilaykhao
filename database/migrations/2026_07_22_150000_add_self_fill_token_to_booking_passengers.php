<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            // ลิงก์เฉพาะคน สำหรับให้เพื่อนร่วมทางกรอกข้อมูลของตัวเอง โดยที่คนจอง
            // ไม่ต้องไล่ถามเลขบัตร/กรุ๊ปเลือด/โรคประจำตัวทางแชท
            $table->string('self_fill_token')->nullable()->unique();
            $table->timestamp('self_fill_expires_at')->nullable();
            $table->timestamp('self_filled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn(['self_fill_token', 'self_fill_expires_at', 'self_filled_at']);
        });
    }
};
