<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            // เจ้าของห้อง = ลูกค้าที่ล็อกอิน (หนึ่งคนมีห้องเดียว)
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview')->nullable();
            // last_read pointers ต่อฝั่ง สำหรับคำนวณ unread ทั้งของลูกค้าและแอดมิน
            $table->unsignedBigInteger('customer_last_read_id')->default(0);
            $table->unsignedBigInteger('admin_last_read_id')->default(0);
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_conversations');
    }
};
