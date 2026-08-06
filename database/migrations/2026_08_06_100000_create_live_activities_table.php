<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live Activity ที่กำลังแสดงอยู่บนหน้าจอล็อก / Dynamic Island ของลูกค้าหนึ่งเครื่อง
     *
     * ตอนตี 4 ที่ยืนรอรถอยู่ข้างถนน ไม่มีใครเปิดแอป — เขาปลดล็อกจอแล้วดู ระบบนี้
     * จึงต้องมี "ปลายทาง" ที่ยิงไปได้โดยไม่ผ่านแอป ซึ่งบน iOS คือ push token ของ
     * ตัว Activity เอง (คนละตัวกับ FCM token: หนึ่ง Activity หนึ่ง token และตายไป
     * พร้อมกับ Activity นั้น) แถวนี้คือคู่ (เครื่อง, ใบจอง) ที่ยัง live อยู่
     *
     * Android ไม่มี ActivityKit — ฝั่งนั้นแอปวาด ongoing notification เอง โดยรับ
     * state ชุดเดียวกันนี้ผ่าน FCM data message จึงไม่ต้องมีแถวที่นี่
     */
    public function up(): void
    {
        Schema::create('live_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();

            $table->string('platform', 20)->default('ios');
            // token ของ Activity ที่ระบบ iOS ออกให้หลังแอปเริ่ม Activity
            $table->string('push_token', 200)->unique();
            // id ฝั่ง ActivityKit — ใช้อ้างกลับตอนแอปอยากปิด Activity เดิมของตัวเอง
            $table->string('activity_id', 100)->nullable();

            // state ล่าสุดที่ยิงออกไป — ใช้กันยิงซ้ำเมื่อไม่มีอะไรเปลี่ยน
            $table->json('state')->nullable();
            $table->string('stage', 30)->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            // ดึง "ทุก Activity ที่ยังไม่จบของรอบนี้" ทุกนาทีตอนรถกำลังวิ่ง
            $table->index(['schedule_id', 'ended_at']);
            $table->index(['booking_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_activities');
    }
};
