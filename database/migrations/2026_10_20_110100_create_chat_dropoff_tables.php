<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ขอลงระหว่างทางขากลับ — แต่ละคนบอกจุดลงล่วงหน้า คนขับได้รายการเรียงตามทาง
        Schema::create('chat_dropoff_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'closed_at']);
        });

        // จุดลงของผู้โดยสารแต่ละคน — ไม่เลือก = จุดรับขาไป (สร้างแถวไว้ตั้งแต่เปิดการ์ด
        // เพื่อจำว่าแจ้งใกล้ถึง/ส่งถึงแล้วหรือยัง)
        Schema::create('chat_dropoff_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('chat_dropoff_rounds')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->foreignId('pickup_point_id')->nullable()->constrained('schedule_pickup_points')->nullOnDelete();
            $table->string('label', 120);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('note', 200)->nullable();
            // null = ค่าตั้งต้นจากจุดรับขาไป ยังไม่มีใครเลือกเอง
            $table->foreignId('chosen_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approach_notified_at')->nullable();
            $table->timestamp('dropped_at')->nullable();
            $table->foreignId('dropped_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['round_id', 'passenger_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_dropoff_choices');
        Schema::dropIfExists('chat_dropoff_rounds');
    }
};
