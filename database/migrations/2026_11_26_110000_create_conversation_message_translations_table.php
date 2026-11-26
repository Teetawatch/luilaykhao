<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คำแปลของแชทส่วนตัวกับทีมงาน (chat_private_messages) และกล่องแชทซัพพอร์ต
 * (support_messages) — โครงเดียวกับ chat_message_translations ของห้องรวม แต่
 * ชี้ข้อความแบบ morph เพราะมีสองตาราง ลบข้อความ = ลบคำแปล (ChatTranslationService::forget)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_translations', function (Blueprint $table) {
            $table->id();
            $table->morphs('translatable', 'conv_msg_translations_translatable_index');
            $table->string('lang', 8);
            $table->char('body_hash', 40);
            $table->text('text');
            $table->string('source_lang', 8)->nullable();
            $table->boolean('same_language')->default(false);
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'lang'], 'conv_msg_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_translations');
    }
};
