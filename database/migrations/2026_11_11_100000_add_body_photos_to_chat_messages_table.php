<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รูปที่แทรกระหว่างบรรทัดของข้อความระบบ (กำหนดการที่มีรูปของแต่ละจุดแวะ)
 * [{after_line, caption, images}] — after_line คือเลขบรรทัด (นับจาก 0) ของ body
 * ที่รูปชุดนี้ต่อท้าย ไม่เก็บข้อความซ้ำ ไคลเอนต์รุ่นเก่าที่ไม่รู้จักคีย์นี้ยังวาด
 * body ได้เหมือนเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->json('body_photos')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('body_photos');
        });
    }
};
