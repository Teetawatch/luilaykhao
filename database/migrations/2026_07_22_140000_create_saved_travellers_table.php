<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_travellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ป้ายที่เจ้าของตั้งเอง เช่น "แม่", "พี่เอ" — ไว้เลือกจากลิสต์ได้เร็ว
            $table->string('label')->nullable();

            $table->string('title')->nullable();
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // ข้อมูลอ่อนไหว เข้ารหัสเหมือนที่ทำกับ users/booking_passengers
            $table->text('id_card')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->text('allergies')->nullable();
            $table->text('health_notes')->nullable();
            $table->boolean('halal_food')->default(false);

            // เรียงคนที่ใช้บ่อย/ล่าสุดขึ้นก่อนในตัวเลือก
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('times_used')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_travellers');
    }
};
