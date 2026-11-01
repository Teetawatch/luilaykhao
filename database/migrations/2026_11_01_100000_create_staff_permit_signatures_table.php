<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลายเซ็นแบบฟอร์มอุทยานของสตาฟประจำรอบ — อุทยานลงทะเบียนทุกคนที่เข้าพื้นที่ ทีมงาน
 * ก็ต้องยื่นแบบฟอร์มเหมือนลูกทริป ข้อมูลในฟอร์มมาจากโปรไฟล์ของสตาฟ เก็บแค่ลายเซ็น
 * และข้อมูลที่ฟอร์มถามตอนเซ็น (ที่อยู่ตามทะเบียนบ้าน) แยกตารางจาก pivot
 * schedule_staff_assignments เพราะการจัดสตาฟใหม่ sync() ลบแถว pivot ทิ้ง
 * ดู StaffPermitService
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_permit_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // ลิงก์เซ็นผ่านเว็บ /staff-permit/{token} — แอดมินส่งให้สตาฟที่ยังไม่อัปแอป
            $table->string('token', 40)->unique();
            $table->string('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedBigInteger('signed_form_id')->nullable();
            $table->json('sign_meta')->nullable();
            $table->text('extra')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->unsignedTinyInteger('reminder_count')->default(0);
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_permit_signatures');
    }
};
