<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เช็คพอยต์บนเส้นทางเดิน — "ใครถึงไหนแล้ว" ตอนกลุ่มเดินแตกเป็นหลายช่วง
        Schema::create('chat_trail_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120)->nullable();
            // ชื่อจุดเรียงตามลำดับเดิน ["จุดพัก 1", "แคมป์", "ยอด"]
            $table->json('checkpoints');
            // ประกาศ "ถึงจุดสุดท้ายครบ" ไปแล้ว — กันประกาศซ้ำ
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'closed_at']);
        });

        // ตำแหน่งล่าสุดของแต่ละคน — reached_index = จุดไกลสุดที่ถึงแล้ว (null = ยังไม่ถึงจุดแรก)
        // help_* = "ตามไม่ทัน ขอพัก" ส่งตรงถึงทีมงาน ไม่ขึ้นในห้องรวม
        Schema::create('chat_trail_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('chat_trail_checks')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->unsignedTinyInteger('reached_index')->nullable();
            $table->timestamp('reached_at')->nullable();
            $table->foreignId('marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('help_at')->nullable();
            $table->string('help_note', 200)->nullable();
            $table->timestamp('help_acked_at')->nullable();
            $table->timestamps();

            $table->unique(['check_id', 'passenger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_trail_positions');
        Schema::dropIfExists('chat_trail_checks');
    }
};
