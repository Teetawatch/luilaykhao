<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ให้ใบจองที่พักจ่ายผ่าน Beam ได้ด้วย
 *
 * ตาราง payments เดิมผูกกับการจองทริปอย่างเดียว (booking_id บังคับ) แถวของที่พัก
 * จึงชี้ไปที่ stay_booking_id แทน — แถวหนึ่งมีอย่างใดอย่างหนึ่งเสมอ ไม่ใช่ทั้งคู่
 *
 * stay_bookings.payment_method บอกว่าใบนั้นจ่ายมาทางไหน (สลิป / beam_*) ให้ทีมงาน
 * รู้ตอนคืนเงิน ว่าเงินก้อนนั้นอยู่ในบัญชีบริษัทหรือยังค้างอยู่ที่ Beam
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_id')->nullable()->change();
            $table->foreignId('stay_booking_id')->nullable()->after('booking_id')
                ->constrained('stay_bookings')->cascadeOnDelete();
            $table->index(['stay_booking_id', 'status']);
        });

        Schema::table('stay_bookings', function (Blueprint $table) {
            $table->string('payment_method', 40)->nullable()->after('payment_ref');
        });
    }

    /**
     * booking_id ไม่ถูกบังคับกลับเป็น NOT NULL — แถวของที่พักที่มีอยู่จะทำให้ย้อนไม่ผ่าน
     *
     * MySQL ไม่ยอมลบ index ที่ foreign key ยังใช้อยู่ ต้องลบ foreign key ก่อน
     * และ DDL ของ MySQL ไม่อยู่ใน transaction ทุกขั้นจึงเช็กก่อนลบ ให้รันซ้ำได้
     */
    public function down(): void
    {
        if (Schema::hasColumn('stay_bookings', 'payment_method')) {
            Schema::table('stay_bookings', function (Blueprint $table) {
                $table->dropColumn('payment_method');
            });
        }

        if (! Schema::hasColumn('payments', 'stay_booking_id')) {
            return;
        }

        if (collect(Schema::getForeignKeys('payments'))->contains(fn ($fk) => $fk['columns'] === ['stay_booking_id'])) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropForeign(['stay_booking_id']));
        }

        if (collect(Schema::getIndexes('payments'))->contains('name', 'payments_stay_booking_id_status_index')) {
            Schema::table('payments', fn (Blueprint $table) => $table->dropIndex(['stay_booking_id', 'status']));
        }

        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('stay_booking_id'));
    }
};
