<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คูปองที่ลูกค้ากด "เก็บ" ในแอป — ผูกกับบัญชี เปลี่ยนเครื่อง/ลบแอปแล้วยังอยู่
 *
 * เก็บแค่ว่าใครเก็บโค้ดไหน เงื่อนไขจริง (หมดอายุ / ใช้ครบ / เฉพาะทริป) ยังอ่าน
 * จากแถว promotions ตอนแสดงผลและตอนตรวจโค้ดเสมอ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'promotion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_promotions');
    }
};
