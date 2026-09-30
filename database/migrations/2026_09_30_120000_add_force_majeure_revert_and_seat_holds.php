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
 *
 * ทุกขั้นเช็คก่อนสร้าง: ฉบับแรกของไฟล์นี้ล้มบน MySQL ครึ่งทาง (ชื่อ index ที่ Laravel
 * ตั้งให้ยาว 65 ตัว เกินเพดาน 64 ของ MySQL — SQLite ในเทสต์ไม่จำกัดจึงไม่เจอ) ตาราง
 * และคอลัมน์ถูกสร้างไปแล้วแต่ migration ไม่ถูกจด รันซ้ำจึงต้องข้ามของที่มีอยู่
 */
return new class extends Migration
{
    private const HOLDS = 'force_majeure_seat_holds';

    // ตั้งชื่อเองให้สั้น — ชื่ออัตโนมัติของ index นี้ยาวเกิน 64 ตัวอักษร
    private const LOOKUP_INDEX = 'fm_seat_holds_lookup_index';

    private const UNIQUE_INDEX = 'fm_seat_holds_schedule_booking_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('trip_schedules', 'force_majeure_prev_status')) {
            Schema::table('trip_schedules', function (Blueprint $table) {
                $table->string('force_majeure_prev_status', 20)->nullable();
            });
        }

        if (! Schema::hasColumn('waitlist_entries', 'force_majeure_closed_at')) {
            Schema::table('waitlist_entries', function (Blueprint $table) {
                $table->timestamp('force_majeure_closed_at')->nullable();
            });
        }

        if (! Schema::hasTable(self::HOLDS)) {
            Schema::create(self::HOLDS, function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('seat_count');
                $table->timestamp('expires_at');
                $table->timestamp('released_at')->nullable();
                $table->timestamps();

                $table->unique(['schedule_id', 'booking_id'], self::UNIQUE_INDEX);
                $table->index(['schedule_id', 'released_at', 'expires_at'], self::LOOKUP_INDEX);
            });

            return;
        }

        // ตารางค้างจากรอบที่ล้ม — เติมเฉพาะส่วนที่ยังขาด
        $indexes = collect(Schema::getIndexes(self::HOLDS))->pluck('name');
        $foreignKeys = collect(Schema::getForeignKeys(self::HOLDS))->pluck('name');
        $hasUnique = $indexes->contains(self::UNIQUE_INDEX)
            || $indexes->contains('force_majeure_seat_holds_schedule_id_booking_id_unique');

        Schema::table(self::HOLDS, function (Blueprint $table) use ($indexes, $foreignKeys, $hasUnique) {
            if (! $foreignKeys->contains('force_majeure_seat_holds_schedule_id_foreign')) {
                $table->foreign('schedule_id')->references('id')->on('trip_schedules')->cascadeOnDelete();
            }
            if (! $foreignKeys->contains('force_majeure_seat_holds_booking_id_foreign')) {
                $table->foreign('booking_id')->references('id')->on('bookings')->cascadeOnDelete();
            }
            if (! $foreignKeys->contains('force_majeure_seat_holds_user_id_foreign')) {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
            if (! $hasUnique) {
                $table->unique(['schedule_id', 'booking_id'], self::UNIQUE_INDEX);
            }
            if (! $indexes->contains(self::LOOKUP_INDEX)) {
                $table->index(['schedule_id', 'released_at', 'expires_at'], self::LOOKUP_INDEX);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::HOLDS);

        if (Schema::hasColumn('waitlist_entries', 'force_majeure_closed_at')) {
            Schema::table('waitlist_entries', function (Blueprint $table) {
                $table->dropColumn('force_majeure_closed_at');
            });
        }

        if (Schema::hasColumn('trip_schedules', 'force_majeure_prev_status')) {
            Schema::table('trip_schedules', function (Blueprint $table) {
                $table->dropColumn('force_majeure_prev_status');
            });
        }
    }
};
