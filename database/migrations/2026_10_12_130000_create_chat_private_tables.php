<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ถามทีมงานแบบส่วนตัว — เธรดแยกระหว่างลูกทริปหนึ่งคนกับทีมงานของรอบ
 *
 * ตั้งใจแยกตารางจาก chat_messages: ทุกคิวรีของห้องรวม (ยังไม่อ่าน, push, ไทม์ไลน์,
 * ตอบอัตโนมัติ, ล้างห้อง) อ่านตารางนั้น ถ้าใช้แถวเดียวกันแล้วพลาดเงื่อนไขเดียว
 * ข้อความส่วนตัวจะหลุดเข้าห้องรวมทันที
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_private_threads')) {
            Schema::create('chat_private_threads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
                // ลูกทริปเจ้าของเธรด — ฝั่งทีมงานคือใครก็ได้ที่ดูแลรอบนี้
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // อ่านถึงข้อความไหนแล้ว แยกฝั่ง — ทีมงานอ่านคนเดียวถือว่าทีมอ่านแล้ว
                $table->unsignedBigInteger('customer_read_id')->default(0);
                $table->unsignedBigInteger('staff_read_id')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'user_id'], 'chat_private_threads_schedule_user_unique');
            });
        }

        if (! Schema::hasTable('chat_private_messages')) {
            Schema::create('chat_private_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')->constrained('chat_private_threads')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sender_role', 16);
                $table->text('body')->nullable();
                $table->string('image_path')->nullable();
                $table->string('audio_path')->nullable();
                $table->unsignedInteger('audio_duration_ms')->nullable();
                $table->boolean('is_deleted')->default(false);
                $table->timestamps();

                $table->index(['thread_id', 'id'], 'chat_private_messages_thread_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_private_messages');
        Schema::dropIfExists('chat_private_threads');
    }
};
