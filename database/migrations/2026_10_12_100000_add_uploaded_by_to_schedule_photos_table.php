<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใครเป็นคนอัปรูปประจำรอบ — สตาฟอัปรูปเข้าอัลบั้มรอบได้เองจากแอปแล้ว จึงต้องรู้ว่า
 * รูปไหนเป็นของใคร: สตาฟลบได้เฉพาะรูปที่ตัวเองอัป ส่วนรูปของแอดมิน (null = อัปก่อนมี
 * คอลัมน์นี้ หรือคนอัปถูกลบบัญชีไปแล้ว) แตะไม่ได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_photos', function (Blueprint $table) {
            $table->foreignId('uploaded_by')->nullable()->after('height')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('uploaded_by');
        });
    }
};
