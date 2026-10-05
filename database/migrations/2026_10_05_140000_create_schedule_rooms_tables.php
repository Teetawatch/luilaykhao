<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ห้องพักของรอบ — ทีมงานจัดว่าใครนอนห้องไหนกับใคร ลูกทริปเห็นในแอป
        Schema::create('schedule_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // แยกที่พักหลายคืน/หลายที่ เช่น "คืนแรก · ภูชี้ฟ้า" — ว่าง = ที่พักเดียวทั้งทริป
            $table->string('stay_label', 80)->nullable();
            $table->string('name', 60);
            $table->string('note', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['schedule_id', 'sort_order']);
        });

        Schema::create('schedule_room_guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('schedule_rooms')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['room_id', 'passenger_id']);
            $table->index('passenger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_room_guests');
        Schema::dropIfExists('schedule_rooms');
    }
};
