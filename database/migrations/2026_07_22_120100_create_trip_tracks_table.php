<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // เส้นทางที่ลดรูปแล้ว + ค่าที่คำนวณจากฝั่งเซิร์ฟเวอร์เสมอ
            // (ไม่เชื่อตัวเลขที่แอปส่งมา เพราะเป็นสถิติที่เอาไปเทียบกับคนอื่น)
            $table->json('points');
            $table->decimal('distance_km', 8, 2)->default(0);
            $table->integer('elevation_gain_m')->default(0);
            $table->integer('elevation_loss_m')->default(0);
            $table->integer('max_elevation_m')->nullable();
            $table->integer('moving_seconds')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // หนึ่งคนมีแทร็กเดียวต่อหนึ่งรอบ — อัปโหลดซ้ำคือการอัปเดตของเดิม
            $table->unique(['user_id', 'schedule_id']);
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_tracks');
    }
};
