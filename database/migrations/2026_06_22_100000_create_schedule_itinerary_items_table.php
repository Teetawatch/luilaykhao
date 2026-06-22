<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // วันที่ของรายการ — null ได้ (ทริปวันเดียว/ยังไม่ระบุวัน) ใช้กรุ๊ปทริปหลายวัน
            $table->date('item_date')->nullable();
            // เวลาเริ่มแบบ "HH:MM" (เก็บ string ให้ตรงกับ pickup_time) — null = ทั้งวัน/ไม่ระบุ
            $table->string('time', 5)->nullable();
            $table->string('title');
            $table->text('detail')->nullable();
            // ลำดับเมื่อวัน/เวลาเท่ากันหรือไม่ระบุเวลา
            $table->unsignedInteger('sort_order')->default(0);
            // ผู้สร้าง (admin/operator) — null ได้เผื่อผู้ใช้ถูกลบ
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'item_date', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_itinerary_items');
    }
};
