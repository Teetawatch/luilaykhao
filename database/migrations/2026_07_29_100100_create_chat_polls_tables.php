<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // ข้อความในห้องที่เป็น "การ์ดโพล" — ลบข้อความ = ลบโพลตามไปด้วย
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('question', 200);
            $table->boolean('allow_multiple')->default(false);
            $table->timestamp('closes_at')->nullable();   // ปิดอัตโนมัติเมื่อถึงเวลา
            $table->timestamp('closed_at')->nullable();   // ปิดด้วยมือโดยคนสร้าง/สตาฟ
            $table->timestamps();

            $table->index(['schedule_id', 'created_at']);
        });

        Schema::create('chat_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
            $table->string('label', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['poll_id', 'sort_order']);
        });

        Schema::create('chat_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('chat_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // หนึ่งคนกดตัวเลือกเดิมได้ครั้งเดียว (โพลหลายตัวเลือกจะมีหลายแถวต่อคน)
            $table->unique(['option_id', 'user_id']);
            $table->index(['poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_poll_votes');
        Schema::dropIfExists('chat_poll_options');
        Schema::dropIfExists('chat_polls');
    }
};
