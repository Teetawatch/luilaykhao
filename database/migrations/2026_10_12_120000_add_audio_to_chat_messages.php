<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ข้อความเสียงในห้องแชททริป — เก็บไฟล์บน MediaDisk ใต้ chat/audio/ (ถูกลบพร้อมห้อง
 * ใน PurgeEndedTripChatsJob) และความยาวที่แอปวัดได้ตอนอัด ไว้วาดแถบเวลาก่อนโหลดไฟล์
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('chat_messages', 'audio_path')) {
                $table->string('audio_path')->nullable()->after('image_path');
            }
            if (! Schema::hasColumn('chat_messages', 'audio_duration_ms')) {
                $table->unsignedInteger('audio_duration_ms')->nullable()->after('audio_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['audio_path', 'audio_duration_ms']);
        });
    }
};
