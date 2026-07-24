<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_no')->unique();       // RC-YYYYMM-XXXX
            $table->string('verify_token', 32)->unique();  // สำหรับหน้า /receipt/{token}
            $table->string('kind')->default('full');       // full|deposit|installment|split|balance
            $table->decimal('amount', 10, 2)->default(0);  // ยอดที่รับในใบเสร็จนี้
            $table->string('currency', 3)->default('THB');
            $table->string('status')->default('paid');
            $table->json('snapshot')->nullable();          // ข้อมูล ณ วันออก เพื่อให้เอกสารไม่เปลี่ยนตามภายหลัง
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // ออกใบเสร็จหนึ่งใบต่อ (การจอง + ชนิดการรับเงิน) — กันออกซ้ำเมื่อยิงยืนยันหลายรอบ
            $table->unique(['booking_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
