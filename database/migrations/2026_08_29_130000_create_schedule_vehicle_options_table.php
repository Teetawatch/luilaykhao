<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ตัวเลือกยานพาหนะของรอบเดินทาง — "รอบเดียวกัน แต่เลือกได้ว่าจะนั่งคันไหน"
     *
     * รอบใหญ่บางรอบวิ่งทั้งรถบัสและรถตู้พร้อมกัน ต้นทุนต่อหัวไม่เท่ากัน ลูกค้าจึง
     * ต้องเลือกได้เองตอนจองและเห็นส่วนต่างราคาก่อนกดจ่าย (`trip_schedules.transport_type`
     * ยังเป็นพาหนะหลักของรอบไว้ใช้กับงานอื่น ๆ ที่ต้องรู้ว่ารอบนี้แนวไหน)
     *
     * ราคาเก็บเป็น "ส่วนต่างต่อคน" ไม่ใช่ราคาเต็ม เพราะสายราคาที่มีอยู่แล้ว
     * (ราคาทริป → ราคาทับของรอบ → ราคาจุดขึ้นรถซึ่งทับทั้งก้อน) มีเจ้าของชัดเจน
     * อยู่แล้ว การให้ตัวเลือกรถทับซ้ำอีกชั้นจะเกิดคำถามว่าใครชนะเมื่อทั้งคู่ตั้งค่า
     * — ส่วนต่างบวกท้ายสุดจึงคิดกับจุดรับทุกจุดได้โดยไม่ต้องตั้งราคาซ้ำทุกคู่
     *
     * `seats` เป็นโควตาย่อยในกองเดียวกับ total_seats ของรอบ (แบบเดียวกับ
     * join_trip_seats) — null = ไม่จำกัดเป็นรายตัวเลือก ใช้เพดานรวมของรอบอย่างเดียว
     */
    public function up(): void
    {
        Schema::create('schedule_vehicle_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->string('label', 60);                          // รถบัส / รถตู้ VIP
            $table->string('transport_type', 20)->nullable();     // van / bus / boat — ไว้เลือกไอคอน
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->decimal('price_adjustment', 10, 2)->default(0); // ต่อคน บวกหรือลบก็ได้
            $table->unsignedSmallInteger('seats')->nullable();      // null = ใช้เพดานรวมของรอบ
            $table->unsignedSmallInteger('booked_seats')->default(0);
            $table->string('note')->nullable();                     // "ที่นั่งกว้าง มีห้องน้ำบนรถ"
            $table->string('image_url', 2048)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['schedule_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_vehicle_options');
    }
};
