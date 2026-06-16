<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // ผู้โพสต์ (operator/admin) — null ได้เผื่อระบบโพสต์เอง
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // หมวดประกาศ ขับเคลื่อนไอคอน/สีในแอป
            $table->enum('category', [
                'general',        // ทั่วไป
                'meeting_point',  // เปลี่ยนจุดนัดพบ
                'schedule_change',// เลื่อน/เปลี่ยนเวลา
                'packing',        // ของที่ต้องเตรียม
                'weather',        // สภาพอากาศ
                'urgent',         // ด่วน/สำคัญมาก
            ])->default('general');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['schedule_id', 'id']);
            $table->index(['schedule_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_announcements');
    }
};
