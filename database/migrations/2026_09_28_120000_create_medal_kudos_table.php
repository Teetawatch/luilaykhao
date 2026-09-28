<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ปรบมือ (kudos) ให้เหรียญของเพื่อนร่วมรอบ — หนึ่งคนปรบมือให้หนึ่งเหรียญได้ครั้งเดียว
 * ผูกกับเหรียญ ไม่ใช่กับบัญชี เหรียญถูกโอนไปพร้อมที่นั่ง เสียงปรบมือก็ตามไปด้วย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medal_kudos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medal_id')->constrained('trip_medals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['medal_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medal_kudos');
    }
};
