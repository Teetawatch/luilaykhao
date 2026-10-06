<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // คำขอแบบไม่บอกชื่อขยายจาก "ขอแวะห้องน้ำ" เป็นเรื่องเกรงใจอื่นบนรถด้วย
        // (แอร์หนาว/ร้อน, ขับเร็วไป, ขอเบาเพลง) — แถวเดิมทั้งหมดคือห้องน้ำ
        Schema::table('chat_stop_requests', function (Blueprint $table) {
            $table->string('kind', 20)->default('toilet')->after('user_id');
            $table->index(['schedule_id', 'kind', 'acknowledged_at']);
        });

        // ขอยา/ของจำเป็นจากสตาฟ ส่งถึงที่นั่ง — บอกชื่อ (สตาฟต้องเดินไปให้ถูกคน)
        // แต่ไม่กระจายเข้าห้องรวม เพราะ "ใครขอยาอะไร" เป็นเรื่องสุขภาพ
        Schema::create('chat_supply_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // ขอให้ใคร — คนที่จองให้ทั้งกลุ่มขอแทนเพื่อนข้าง ๆ ได้
            $table->foreignId('passenger_id')->nullable()->constrained('booking_passengers')->nullOnDelete();
            $table->string('item', 30);
            $table->string('note', 200)->nullable();
            $table->string('seat_label', 40)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_note', 200)->nullable();
            $table->foreignId('handled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'delivered_at', 'declined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_supply_requests');

        Schema::table('chat_stop_requests', function (Blueprint $table) {
            $table->dropIndex(['schedule_id', 'kind', 'acknowledged_at']);
            $table->dropColumn('kind');
        });
    }
};
