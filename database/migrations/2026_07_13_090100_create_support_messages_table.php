<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            // ผู้ส่ง: ลูกค้า หรือ แอดมิน/operator; null = ข้อความระบบ (ต้อนรับ/แจ้งอัตโนมัติ)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('sender_role', ['customer', 'admin', 'system'])->default('customer');
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
    }
};
