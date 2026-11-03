<?php

use App\Services\ConquestMapService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หมุดความทรงจำในสมุดสะสมการเดินทาง — ผู้ใช้ปักหมุดตรงที่ที่ไปมาจริง
 * (ยอดดอย น้ำตก จุดกางเต็นท์) พร้อมรูปของตัวเอง
 *
 * หมุดของทริปที่เดินจบแล้วยังคำนวณสดจากการจองเหมือนเดิม ({@see ConquestMapService})
 * ตารางนี้เก็บเฉพาะสิ่งที่ผู้ใช้ใส่เอง — ผูกกับทริปได้ (trip_id) แต่ไม่บังคับ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passport_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('trips')->nullOnDelete();
            $table->string('title', 80);
            $table->text('note')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->date('visited_on');
            // ปักจากไหน: gps = ตำแหน่งเครื่องตอนปัก, map = เลื่อนแผนที่เอง, trip = จุดของทริป
            $table->string('source', 10)->default('map');
            $table->timestamps();

            $table->index(['user_id', 'visited_on']);
        });

        Schema::create('passport_pin_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passport_pin_id')->constrained('passport_pins')->cascadeOnDelete();
            $table->string('disk', 20)->nullable();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('thumb_url', 500)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passport_pin_photos');
        Schema::dropIfExists('passport_pins');
    }
};
