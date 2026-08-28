<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_intake_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_intake_id')->constrained()->cascadeOnDelete();

            // คนแรกที่เปิดลิงก์คือคนติดต่อ เพื่อนที่ตามมาทีหลังไม่ใช่
            $table->boolean('is_lead')->default(false);

            $table->string('title')->nullable();
            $table->string('name');
            $table->string('nickname')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // ชุดเดียวกับ booking_passengers — ดึงไปเปิดการจองได้ตรง ๆ
            // ข้อมูลอ่อนไหวเข้ารหัสที่คอลัมน์เหมือนกันทุกที่ในระบบ
            $table->text('id_card')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('blood_group', 10)->nullable();

            $table->string('name_en')->nullable();
            $table->string('nationality', 2)->nullable();
            $table->text('passport_no')->nullable();
            $table->date('passport_expires_at')->nullable();

            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone', 20)->nullable();
            $table->text('allergies')->nullable();
            $table->text('health_notes')->nullable();
            $table->boolean('halal_food')->default(false);

            $table->string('dive_cert_level')->nullable();
            $table->string('cert_number')->nullable();
            $table->decimal('weight', 5, 1)->nullable();

            $table->timestamps();

            $table->index(['customer_intake_id', 'is_lead']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_intake_people');
    }
};
