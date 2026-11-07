<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คำแปลข้อความแชททริป — แปลเฉพาะตอนมีคนกด แล้วเก็บไว้ให้คนถัดไปที่กดข้อความ
 * เดียวกันเป็นภาษาเดียวกันได้ทันทีโดยไม่ต้องเรียกโมเดลซ้ำ
 *
 * body_hash คือ hash ของข้อความต้นฉบับตอนที่แปล — ข้อความถูกแก้แล้ว hash ไม่ตรง
 * จึงแปลใหม่เอง ไม่ต้องตามล้างแคชตอนแก้ไข
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_message_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('lang', 8);
            $table->char('body_hash', 40);
            $table->text('text');
            // ภาษาของต้นฉบับที่โมเดลอ่านได้ (th/en/zh/…) — ไว้บอกในแอปว่าแปลจากภาษาอะไร
            $table->string('source_lang', 8)->nullable();
            $table->boolean('same_language')->default(false);
            $table->timestamps();

            $table->unique(['message_id', 'lang'], 'chat_msg_translations_msg_lang_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_translations');
    }
};
