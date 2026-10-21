<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สติกเกอร์ของแบรนด์สำหรับห้องแชททริป — แอดมินอัปโหลดเองที่ /admin/stickers
 *
 * ข้อความสติกเกอร์เก็บ sticker_id ไว้คู่กับ body สำรอง ("[สติกเกอร์] รับทราบ")
 * แอปรุ่นเก่า เว็บ หน้าแชทแอดมิน และ push จึงยังอ่านรู้เรื่องโดยไม่ต้องรู้จักสติกเกอร์
 * ลบสติกเกอร์ทิ้งทีหลัง = ข้อความเดิมกลายเป็นข้อความสำรองนั้น (nullOnDelete)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sticker_packs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('sticker_packs')->cascadeOnDelete();
            // คำที่สติกเกอร์สื่อ — ใช้เป็นข้อความสำรองและคำค้นตอนพิมพ์
            $table->string('label', 40);
            // คำอื่นที่ควรเด้งสติกเกอร์นี้ขึ้นมาให้ตอนพิมพ์ คั่นด้วยจุลภาค
            $table->string('keywords', 255)->nullable();
            $table->string('image_path');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->boolean('is_animated')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['pack_id', 'sort_order']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('sticker_id')->nullable()->after('audio_duration_ms')
                ->constrained('stickers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sticker_id');
        });

        Schema::dropIfExists('stickers');
        Schema::dropIfExists('sticker_packs');
    }
};
