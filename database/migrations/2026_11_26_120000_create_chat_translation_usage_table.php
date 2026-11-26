<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * มิเตอร์ค่าแปลแชท — หนึ่งแถวต่อ (วันไทย, รุ่นโมเดล) ดู ChatTranslationMeter
 * และดัชนีหาคำแปลของข้อความเดียวกันเป๊ะข้ามห้อง (body_hash + lang) ให้ใช้ซ้ำได้
 * โดยไม่เรียกโมเดล
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_translation_usage', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('model', 64);
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            // คำแปลที่ได้จากข้อความเดียวกันในห้องอื่น = ไม่ได้เรียกโมเดล
            $table->unsignedInteger('reused')->default(0);
            // ถูกกันเพราะเพดานเดือน
            $table->unsignedInteger('blocked')->default(0);
            $table->timestamps();

            $table->unique(['date', 'model']);
        });

        Schema::table('chat_message_translations', function (Blueprint $table) {
            $table->index(['body_hash', 'lang'], 'chat_msg_translations_hash_lang_index');
        });

        Schema::table('conversation_message_translations', function (Blueprint $table) {
            $table->index(['body_hash', 'lang'], 'conv_msg_translations_hash_lang_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_message_translations', function (Blueprint $table) {
            $table->dropIndex('conv_msg_translations_hash_lang_index');
        });

        Schema::table('chat_message_translations', function (Blueprint $table) {
            $table->dropIndex('chat_msg_translations_hash_lang_index');
        });

        Schema::dropIfExists('chat_translation_usage');
    }
};
