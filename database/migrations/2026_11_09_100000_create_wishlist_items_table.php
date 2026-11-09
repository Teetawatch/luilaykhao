<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทริปที่ถูกใจ (หัวใจ) ผูกกับบัญชี — เดิมเก็บแค่ในเครื่อง (แอป SharedPreferences /
 * เว็บ localStorage) เปลี่ยนมือถือหรือเปิดเว็บแล้วหายหมด
 *
 * trip_alerts.source แยกกระดิ่งที่ผู้ใช้เปิดเอง (manual) ออกจากที่ระบบเปิดให้
 * ตอนกดหัวใจ (wishlist) — เอาหัวใจออกจะได้ปิดเฉพาะกระดิ่งที่ระบบเปิดให้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'trip_id']);
            $table->index('trip_id');
        });

        Schema::table('trip_alerts', function (Blueprint $table) {
            $table->string('source', 16)->default('manual')->after('trip_id');
        });
    }

    public function down(): void
    {
        Schema::table('trip_alerts', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::dropIfExists('wishlist_items');
    }
};
