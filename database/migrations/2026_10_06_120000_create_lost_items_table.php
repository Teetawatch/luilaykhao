<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ของที่ลูกทริปลืมไว้ (บนรถ ที่พัก จุดพัก) — ต้องอยู่ต่อหลังห้องแชทถูกลบ
        // จึงไม่ผูกชีวิตกับข้อความ: ลบข้อความ = แค่ตัดการ์ดในแชทออก ของยังอยู่
        Schema::create('lost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('posted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('photo_path')->nullable();
            $table->string('description', 300);
            $table->foreignId('claimed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            // เจ้าของบอกว่าจะรับคืนยังไง (มารับเอง / ส่งไปรษณีย์ที่อยู่…)
            $table->string('claim_note', 300)->nullable();
            $table->timestamp('returned_at')->nullable();
            // เช่น เลขพัสดุ / "ให้คืนที่ออฟฟิศแล้ว"
            $table->string('returned_note', 300)->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'created_at']);
            $table->index(['returned_at', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_items');
    }
};
