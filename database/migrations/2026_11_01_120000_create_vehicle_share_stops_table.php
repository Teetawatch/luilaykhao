<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สตาฟกดปิดแชร์ตำแหน่งรถเอง — ใคร กี่โมง เพราะอะไร
 *
 * ไม่มีแถวนี้ เซิร์ฟเวอร์แยกไม่ออกว่าพิกัดที่เงียบไปคือการแชร์หลุด (ต้องตาม)
 * หรือสตาฟตั้งใจปิดเพราะถึงที่หมายแล้ว (ไม่ต้องตาม)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_share_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 20);
            // พิกัดล่าสุดของรถตอนกดปิด — มีพิกัดใหม่กว่านี้ = เปิดกลับมาแล้ว การปิดนี้หมดผล
            $table->unsignedBigInteger('last_location_id')->nullable();
            $table->timestamp('stopped_at');
            $table->timestamps();

            $table->index(['schedule_id', 'stopped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_share_stops');
    }
};
