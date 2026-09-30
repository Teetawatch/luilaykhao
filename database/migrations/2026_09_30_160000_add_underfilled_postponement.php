<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รอบที่ไม่ได้ออกเพราะผู้ร่วมทริปไม่ครบ — ใช้กลไกเดียวกับการเลื่อนเพราะเหตุสุดวิสัย
 * (ForceMajeureService) แต่ต่างกันสามเรื่อง จึงต้องจดไว้ว่าการเลื่อนแต่ละครั้งเป็นแบบไหน:
 *
 * - `postpone_kind` — force_majeure | underfilled (null = force_majeure ของเดิม)
 * - `postpone_decide_by` — วันสุดท้ายที่ลูกค้าตัดสินใจได้ (เลือกรอบใหม่ หรือขอคืนเงิน)
 *   เลยแล้วระบบคืนเงินให้เอง ส่วน force_majeure_until ยังเป็น "รอบใหม่ต้องออกไม่เกินวันนี้"
 * - `refund_account` — บัญชีรับเงินคืนที่ลูกค้ากรอกเอง (เข้ารหัส) ทีมงานจะได้โอนได้
 *   โดยไม่ต้องไล่ถาม
 *
 * ทุกขั้นเช็คก่อนว่ามีอยู่แล้วหรือยัง — MySQL ไม่ย้อน DDL ที่ทำไปแล้วเมื่อ migration ล้มกลางทาง
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('trip_schedules', 'postpone_kind')) {
            Schema::table('trip_schedules', function (Blueprint $table) {
                $table->string('postpone_kind', 20)->nullable()->after('force_majeure_reason');
            });
        }

        if (! Schema::hasColumn('bookings', 'postpone_kind')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('postpone_kind', 20)->nullable()->after('force_majeure_reason');
            });
        }

        if (! Schema::hasColumn('bookings', 'postpone_decide_by')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->date('postpone_decide_by')->nullable()->after('force_majeure_until');
            });
        }

        if (! Schema::hasColumn('bookings', 'refund_account')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->text('refund_account')->nullable()->after('refund_slip_path');
            });
        }
    }

    public function down(): void
    {
        foreach (['postpone_kind', 'postpone_decide_by', 'refund_account'] as $column) {
            if (Schema::hasColumn('bookings', $column)) {
                Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasColumn('trip_schedules', 'postpone_kind')) {
            Schema::table('trip_schedules', fn (Blueprint $table) => $table->dropColumn('postpone_kind'));
        }
    }
};
