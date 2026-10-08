<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ยอดเพิ่มเติมบนใบจองที่ยืนยันแล้ว — ส่วนที่ total_amount มากกว่าเงินที่รับมาแล้ว
 * นอกเหนือจากยอดคงเหลือ/งวดที่นัดไว้ เกิดเมื่อแอดมินจองแบบข้ามการชำระเงินแล้วให้
 * ลูกค้าจ่ายทีหลัง หรือแอดมินเพิ่มของ/ปรับยอดให้ลูกค้าหลังยืนยันแล้ว
 *
 * waived_amount = ยอดที่แอดมินยกเว้นไม่เก็บ (จองแบบ "รับเงินแล้ว/ไม่ต้องเก็บ")
 * ไม่ลง paid_amount เพราะรายงานรายรับรวมจาก paid_amount และไม่มีเงินเข้าจริง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('waived_amount', 10, 2)->default(0)->after('paid_amount');
        });

        Schema::create('booking_extra_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method')->nullable();
            $table->string('payment_ref')->nullable();
            $table->string('slip_path')->nullable();
            $table->timestamp('transfer_datetime')->nullable();
            $table->string('slip_ocr_status')->nullable();
            $table->json('slip_ocr_result')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extra_payments');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('waived_amount');
        });
    }
};
