<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ตำแหน่งสดของคนในรอบเดินทาง — "หัวแถวถึงยัง / น้องคนนั้นหายไปไหน"
     *
     * พอขึ้นดอยจริงคนกระจายกันเป็นกิโล แอปเห็นแค่รถแต่ไม่เห็นคน ตารางนี้เก็บ
     * ตำแหน่งล่าสุด "แถวเดียวต่อคนต่อรอบ" โดยตั้งใจ — ไม่ได้เก็บเส้นทางย้อนหลัง
     * เพราะสิ่งที่ต้องตอบคือ "ตอนนี้อยู่ไหน" ไม่ใช่ "เดินทางไปทางไหนมาบ้าง"
     * (ประวัติการเดินของตัวเองมีที่เก็บของมันเองอยู่แล้วใน trip_tracks ซึ่งเจ้าตัว
     * เป็นคนกดบันทึกเอง) การมีแถวอยู่ = ยังแชร์อยู่ เลิกแชร์คือลบแถวทิ้ง
     */
    public function up(): void
    {
        Schema::create('trip_member_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->decimal('altitude_m', 8, 2)->nullable();
            // แบตของเพื่อนคือข้อมูลความปลอดภัย: จุดที่หายไปเพราะแบตหมด ต่างจาก
            // จุดที่หายไปเพราะเดินเข้าอับสัญญาณ
            $table->unsignedTinyInteger('battery_level')->nullable();

            $table->timestamp('recorded_at');
            $table->timestamps();

            // หนึ่งคนหนึ่งแถวต่อรอบ — อัปเดตทับของเดิมเสมอ
            $table->unique(['schedule_id', 'user_id']);
            $table->index(['schedule_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_member_locations');
    }
};
