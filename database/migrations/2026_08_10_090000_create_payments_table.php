<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger ของ "ความพยายามจ่ายเงินหนึ่งครั้ง" ผ่านเกตเวย์
 *
 * เดิมไม่มีที่ไหนบันทึกว่า "มีคนกดจ่าย X บาทเมื่อ 14:32" — สถานะเงินกระจายอยู่บน
 * bookings / installment_payments / booking_split_shares และไม่มี key ไหนที่ map
 * กลับจาก referenceId ที่เกตเวย์ส่งมาได้ ตารางนี้เป็นฝั่งที่คุยกับเกตเวย์อย่างเดียว
 * ส่วนตารางเดิมยังเป็นความจริงทางธุรกิจเหมือนเดิมทุกอย่าง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            // จ่ายเพื่ออะไร + ชี้ไปที่แถวไหน (งวดที่เท่าไร / ส่วนแบ่งของใคร)
            $table->string('purpose', 20);
            $table->unsignedBigInteger('purpose_id')->nullable();

            $table->string('provider', 20)->default('beam');
            $table->string('provider_charge_id')->nullable();

            // สิ่งที่เราส่งให้เกตเวย์และใช้หาแถวนี้กลับตอน webhook เข้า
            $table->string('reference_id', 64)->unique();

            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('THB');
            $table->string('status', 20)->default('pending'); // pending | succeeded | failed | expired
            $table->string('payment_method_type', 40)->nullable();

            // ผู้กดจ่าย — ลิงก์สาธารณะไม่มีคนล็อกอิน จึงเป็น null ได้
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->string('failure_code')->nullable();

            // เก็บดิบไว้สอบสวนย้อนหลัง เวลายอดไม่ตรงกับที่ธนาคารโอนเข้าจริง
            $table->json('raw_response')->nullable();
            $table->json('raw_webhook')->nullable();

            $table->timestamps();

            $table->index('provider_charge_id');
            $table->index(['booking_id', 'status']);
            // ใช้โดย reconcile (หา pending ที่ค้าง) และโดยตัวกัน ExpirePendingBookingsJob
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
