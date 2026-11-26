<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ภาษาที่ผู้ใช้อ่านแชททริป — แชทแปลอัตโนมัติ (แบบ Grab) ต้องรู้ฝั่งเซิร์ฟเวอร์ว่า
 * ในห้องมีคนอ่านภาษาอะไรบ้าง ถึงจะแปลข้อความใหม่ไว้ก่อนที่เขาจะเปิดดู
 *
 * chat_translate_lang = null คือยังไม่เคยเลือก (เดาจากภาษาแอป/สัญชาติ
 * ดู ChatTranslationService::readerLanguage)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('chat_translate_lang', 8)->nullable()->after('locale');
            $table->boolean('chat_auto_translate')->default(true)->after('chat_translate_lang');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['chat_translate_lang', 'chat_auto_translate']);
        });
    }
};
