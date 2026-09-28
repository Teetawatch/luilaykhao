<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ชาเลนจ์ที่ทำสำเร็จแล้ว — ความคืบหน้าคำนวณสดจากเหรียญเสมอ ตารางนี้จำแค่
 * "สำเร็จเมื่อไร" ไว้ส่งแจ้งเตือนครั้งเดียว และโชว์เป็นประวัติความสำเร็จ
 *
 * period = "2026" (รายปี) หรือ "2026-09" (รายเดือน) ตามเวลาไทย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('challenge_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('challenge_key', 40);
            $table->string('period', 7);
            $table->date('completed_on');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'challenge_key', 'period']);
            $table->index('notified_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_completions');
    }
};
