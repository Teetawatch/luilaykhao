<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รอบที่ออกไม่ได้เพราะเหตุสุดวิสัย (น้ำป่า พายุ อุทยานสั่งปิด) — เงื่อนไขข้อ 6
 * ให้ลูกค้าเลือกรอบใหม่ของทริปเดิมได้เองภายใน 6 เดือน แทนการคืนเงิน
 *
 * ฝั่งรอบ: จดว่ายกเลิกเพราะอะไร เมื่อไหร่ (status = cancelled อยู่แล้ว)
 * ฝั่งใบจอง: สิทธิ์เลือกรอบใหม่ — ใบจองยัง confirmed ตามเดิม เงินยังอยู่กับเรา
 * `force_majeure_resolved_at` ว่าง = ยังรอลูกค้าเลือก
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->timestamp('force_majeure_at')->nullable();
            $table->string('force_majeure_reason', 255)->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('force_majeure_at')->nullable();
            $table->string('force_majeure_reason', 255)->nullable();
            // เลือกรอบใหม่ที่ออกเดินทางได้ไม่เกินวันนี้ (วันเดินทางเดิม + 6 เดือน)
            $table->date('force_majeure_until')->nullable();
            $table->foreignId('force_majeure_schedule_id')->nullable()
                ->constrained('trip_schedules')->nullOnDelete();
            $table->timestamp('force_majeure_resolved_at')->nullable();

            $table->index(['force_majeure_at', 'force_majeure_resolved_at'], 'bookings_force_majeure_pending_index');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_force_majeure_pending_index');
            $table->dropConstrainedForeignId('force_majeure_schedule_id');
            $table->dropColumn([
                'force_majeure_at', 'force_majeure_reason', 'force_majeure_until',
                'force_majeure_resolved_at',
            ]);
        });

        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['force_majeure_at', 'force_majeure_reason']);
        });
    }
};
