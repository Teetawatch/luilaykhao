<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หลักฐานการส่งอีเมล — หนึ่งแถวต่อผู้รับหนึ่งคนต่อหนึ่งฉบับ
 *
 * เริ่มจากอีเมลแจ้งคนไม่ครบ 7 วันก่อนเดินทาง เพราะเป็นฉบับที่ลูกค้าย้อนมาถามบ่อย
 * ว่า "ไม่เห็นได้รับแจ้งเลย" ทีมงานต้องเปิดให้ดูได้ว่าส่งไปที่อยู่ไหน เมื่อไหร่
 * Brevo รับไปแล้วหรือยัง (Message-ID) และเนื้อหาที่ส่งออกไปจริงเป็นอย่างไร
 *
 * FK เป็น nullOnDelete และแช่รหัสจองไว้ด้วย — หลักฐานต้องอยู่ต่อแม้ใบจองถูกลบ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 64);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('trip_schedules')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booking_ref', 32)->nullable();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            // queued → sent | failed; skipped = ใบจองนี้ไม่มีอีเมลที่ส่งถึงได้
            $table->string('status', 16)->default('queued');
            $table->string('message_id')->nullable();
            $table->longText('html_body')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'created_at'], 'email_logs_type_created_idx');
            $table->index(['type', 'schedule_id'], 'email_logs_type_schedule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
