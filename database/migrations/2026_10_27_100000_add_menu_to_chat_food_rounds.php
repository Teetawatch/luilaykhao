<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เมนูให้ลูกทริปแตะเลือกแทนการพิมพ์ — {sets: [หมวดของร้าน], items: [เมนูเฉพาะร้าน]}
        // null = รอบเก่า/ไม่ได้ระบุ แอปโชว์ทุกหมวด
        Schema::table('chat_food_rounds', function (Blueprint $table) {
            $table->json('menu')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('chat_food_rounds', function (Blueprint $table) {
            $table->dropColumn('menu');
        });
    }
};
