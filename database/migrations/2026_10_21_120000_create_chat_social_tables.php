<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลูกเล่นให้ห้องแชทมีชีวิต
 *
 * - chat_member_intros — การ์ดแนะนำตัวของลูกทริป (มาจากไหน เคยเดินป่าไหม ฯลฯ)
 *   หนึ่งใบต่อคนต่อรอบ ผูกกับข้อความในห้อง หายไปพร้อมห้องแชท
 * - chat_birthday_greetings — วันเกิดที่ตรงกับวันเดินทาง: บอกทีมงานแล้วหรือยัง /
 *   อวยพรในห้องแล้วหรือยัง (ไม่เก็บอายุ ไม่ประกาศปีเกิด)
 * - users.social_handles + trip_contact_exchanges — แลก LINE/IG กับเพื่อนร่วมทริป
 *   แบบต้องยินยอมทั้งสองฝ่าย อยู่ต่อหลังห้องแชทถูกลบ (เป็นของเจ้าของบัญชี ไม่ใช่ของห้อง)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_member_intros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->string('hometown', 60)->nullable();
            // first = ครั้งแรก · some = เคยไปบ้าง · lots = สายลุย
            $table->string('experience', 16)->nullable();
            $table->string('note', 160)->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id']);
        });

        Schema::create('chat_birthday_greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
            $table->timestamp('staff_notified_at')->nullable();
            $table->timestamp('greeted_at')->nullable();
            $table->foreignId('greeted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['schedule_id', 'passenger_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // {line, instagram, facebook, tiktok} — แสดงให้เฉพาะคนที่แลกกันแล้ว
            $table->json('social_handles')->nullable();
        });

        Schema::create('trip_contact_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('users')->cascadeOnDelete();
            // pending | accepted | declined
            $table->string('status', 16)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'requester_id', 'target_id'], 'trip_contact_ex_unique');
            $table->index(['target_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_contact_exchanges');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('social_handles');
        });
        Schema::dropIfExists('chat_birthday_greetings');
        Schema::dropIfExists('chat_member_intros');
    }
};
