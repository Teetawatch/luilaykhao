<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ส่งต่อที่นั่ง — คนที่ไปไม่ได้ (น้ำท่วม ป่วย ติดงาน) ส่งลิงก์ให้คนอื่นมารับที่นั่งไปแทน
 * ที่นั่งจะได้ไม่ว่างเปล่า และไม่ต้องพึ่งทีมงานแก้รายชื่อให้ทีละคน
 *
 * หนึ่งแถว = ลิงก์หนึ่งอันของผู้เดินทางหนึ่งคน เก็บไว้หลังใช้แล้วด้วย เพราะเป็น
 * ประวัติว่าใครส่งต่อให้ใครเมื่อไหร่ (ทีมงานต้องตอบได้ตอนรายชื่อประกันไม่ตรง)
 *
 * ตั้งชื่อ index เองให้สั้น — ชื่ออัตโนมัติยาวเกิน 64 ตัวของ MySQL ได้ง่าย
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->string('status', 16)->default('pending');
            // ผู้รับได้เป็นเจ้าของการจองด้วย (คนที่ไปไม่ได้คือเจ้าของการจองเอง)
            $table->boolean('transfers_ownership')->default(false);
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('created_by_staff')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            // สำเนาตอนเกิดเหตุ — ชื่อบนผู้เดินทางถูกเขียนทับไปแล้ว ประวัติต้องอ่านเองได้
            $table->string('previous_name')->nullable();
            $table->string('new_name')->nullable();
            $table->unsignedBigInteger('previous_owner_id')->nullable();
            $table->unsignedBigInteger('previous_member_user_id')->nullable();
            // หลักฐานการยอมรับเงื่อนไขของผู้รับ (คนละคนกับผู้จองที่ยอมรับไว้ตอนจอง)
            $table->string('terms_version', 20)->nullable();
            $table->string('channel', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status'], 'seat_handovers_booking_status_idx');
            $table->index(['passenger_id', 'status'], 'seat_handovers_passenger_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_handovers');
    }
};
