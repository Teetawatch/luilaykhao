<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เช็คอาการ "ยังไหวไหม" — คำตอบเห็นแค่ทีมงานกับคนตอบ ห้องรวมเห็นแค่จำนวนที่ตอบแล้ว
        Schema::create('chat_wellness_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label', 120)->nullable();
            // เตือนคนที่ยังไม่ตอบไปแล้ว — ครั้งเดียว
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'closed_at']);
        });

        Schema::create('chat_wellness_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_id')->constrained('chat_wellness_checks')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            // ok | tired | unwell
            $table->string('status', 10);
            $table->string('note', 200)->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            // ทีมงานกด "ไปดูแลแล้ว" — คนตอบได้รู้ว่ามีคนมาหา
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['check_id', 'passenger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_wellness_answers');
        Schema::dropIfExists('chat_wellness_checks');
    }
};
