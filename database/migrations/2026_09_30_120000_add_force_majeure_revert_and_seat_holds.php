<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ต่อจากสิทธิ์เลือกรอบใหม่ (เหตุสุดวิสัย):
 *
 * - ย้อนกลับได้เมื่อกดผิดรอบ — ต้องจำว่ารอบเคยมีสถานะอะไร และคิวรอคนไหนถูกปิด
 *   เพราะการเลื่อนครั้งนี้ (ไม่ใช่คนที่ออกจากคิวเอง) เพื่อคืนให้ครบ
 * - กันที่นั่งในรอบที่เปิดใหม่ให้คนที่ถูกเลื่อนก่อนคนทั่วไปช่วงแรก
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->string('force_majeure_prev_status', 20)->nullable();
        });

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->timestamp('force_majeure_closed_at')->nullable();
        });

        Schema::create('force_majeure_seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('seat_count');
            $table->timestamp('expires_at');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'booking_id']);
            $table->index(['schedule_id', 'released_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('force_majeure_seat_holds');

        Schema::table('waitlist_entries', function (Blueprint $table) {
            $table->dropColumn('force_majeure_closed_at');
        });

        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('force_majeure_prev_status');
        });
    }
};
