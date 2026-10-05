<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // จุดพักระหว่างทาง — สตาฟนัดเวลากลับรถในห้องแชท แล้วเช็คชื่อขึ้นรถ
        Schema::create('chat_rest_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('place', 120)->nullable();
            $table->timestamp('return_at');
            // เตือน "อีก 5 นาทีรถออก" / "ถึงเวลาแล้ว" ไปแล้วเมื่อไร — กันเด้งซ้ำ
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('due_notified_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'departed_at']);
        });

        Schema::create('chat_rest_stop_boardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stop_id')->constrained('chat_rest_stops')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->foreignId('marked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['stop_id', 'passenger_id']);
        });

        // "ขอแวะห้องน้ำ" แบบไม่บอกชื่อ — user_id เก็บไว้กันกดรัวและแจ้งกลับ
        // แต่ไม่เคยส่งออกไปให้ใครเห็น
        Schema::create('chat_stop_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('urgent')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_stop_requests');
        Schema::dropIfExists('chat_rest_stop_boardings');
        Schema::dropIfExists('chat_rest_stops');
    }
};
