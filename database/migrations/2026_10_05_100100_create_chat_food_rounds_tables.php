<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รอบรับออเดอร์อาหาร (เช่น แวะร้านตามสั่งขากลับ) — สตาฟเปิดในห้องแชท
        // ลูกค้าพิมพ์เมนูของตัวเองระหว่างนั่งรถ สตาฟเอารายการรวมไปสั่งทีเดียว
        Schema::create('chat_food_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // การ์ดในห้อง — ลบข้อความ (หรือห้องถูกล้างหลังจบทริป) = ลบรอบนี้ตามไปด้วย
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 120);
            $table->string('note', 300)->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('announced_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'created_at']);
        });

        Schema::create('chat_food_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('chat_food_rounds')->cascadeOnDelete();
            // null = สตาฟจดแทนคนที่ไม่ได้ใช้แอป (ใช้ guest_name แทน)
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_name', 80)->nullable();
            $table->json('items')->nullable();
            // "ไม่สั่ง" — ให้สตาฟรู้ว่าไม่ต้องรอคนนี้
            $table->boolean('skipped')->default(false);
            $table->foreignId('entered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['round_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_food_orders');
        Schema::dropIfExists('chat_food_rounds');
    }
};
