<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เหรียญพิชิต — หนึ่งแถวต่อหนึ่งคนต่อหนึ่งรอบที่เดินจบจริง
 *
 * finisher_no เป็นลำดับของคนที่พิชิตทริปนี้ (นับข้ามทุกรอบ) และถูก *เก็บ* ไว้
 * ไม่ใช่คำนวณสด เพราะเลขนี้ถูกแชร์ออกไปแล้ว ("Finisher #27") มันต้องไม่ขยับ
 * เมื่อมีคนถูกเพิ่มเข้ารอบเก่าทีหลัง ดู App\Services\MedalService
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_medals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('finisher_no');
            // วันสุดท้ายของทริป — วันที่ "พิชิต" ที่พิมพ์บนเหรียญ
            $table->date('earned_on');
            // ลิงก์สาธารณะ /m/{token} — แยกจากโทเคนอื่นของใบจองทั้งหมดโดยเจตนา
            $table->string('share_token', 24)->unique();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'schedule_id']);
            $table->unique(['trip_id', 'finisher_no']);
            $table->index(['schedule_id', 'booking_id']);
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_medals');
    }
};
