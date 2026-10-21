<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "รับทราบ" ใต้ประกาศของทีมงาน — แทนการที่ลูกทริป 30 คนพิมพ์ "รับทราบครับ" ตามกัน
 * จนประกาศหลุดจอ ทีมงานเห็นว่าใครยังไม่กด แล้วสะกิดเฉพาะคนนั้นได้
 *
 * ack_requested_at บนข้อความ = ทีมงานขอให้กดรับทราบ (ใช้ได้ทั้งข้อความของสตาฟและ
 * ข้อความระบบ เช่น สรุปก่อนวันเดินทาง) · ack_nudged_at กันกดสะกิดรัว
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->timestamp('ack_requested_at')->nullable()->after('pinned_by_id');
            $table->foreignId('ack_requested_by_id')->nullable()->after('ack_requested_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('ack_nudged_at')->nullable()->after('ack_requested_by_id');
        });

        Schema::create('chat_message_acks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['message_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_acks');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ack_requested_by_id');
            $table->dropColumn(['ack_requested_at', 'ack_nudged_at']);
        });
    }
};
