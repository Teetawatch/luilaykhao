<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            // user ที่ถูกผูกเข้ากับการจอง (null = คำเชิญที่ยังไม่ถูกรับ)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // ช่อง passenger ที่สมาชิกคนนี้แทน (optional)
            $table->foreignId('passenger_id')->nullable()
                ->constrained('booking_passengers')->nullOnDelete();
            $table->string('role')->default('companion'); // owner | companion
            $table->string('status')->default('pending');  // pending | active | revoked
            // โทเค็นสำหรับลิงก์เชิญ (มีเฉพาะตอน pending)
            $table->string('invite_token', 32)->nullable()->unique();
            // ชื่อ/ป้ายกำกับที่เจ้าของกรอกไว้ให้คำเชิญ เช่นชื่อเล่นเพื่อน
            $table->string('invite_label')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // user หนึ่งคนเป็นสมาชิกของการจองหนึ่งใบได้ครั้งเดียว
            $table->unique(['booking_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_members');
    }
};
