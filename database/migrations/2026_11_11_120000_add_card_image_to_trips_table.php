<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รูปการ์ดทริปที่ย่อแล้ว (GenerateTripCardImageJob) — เดิมการ์ดในหน้าแรกโหลดรูปปก
 * ขนาดเต็มจากที่แอดมินอัปโหลด หลาย MB ต่อใบ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('card_image', 1024)->nullable();
            // รูปต้นทางที่ card_image ย่อมาจาก — ไม่ตรงกับรูปปัจจุบัน = ยังไม่ได้ย่อรูปใหม่
            $table->string('card_image_source', 1024)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['card_image', 'card_image_source']);
        });
    }
};
