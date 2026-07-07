<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_split_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('booking_members')->nullOnDelete();
            $table->foreignId('passenger_id')->nullable()->constrained('booking_passengers')->nullOnDelete();
            $table->string('label')->nullable(); // ชื่อแสดงผลกรณีไม่ได้ผูกสมาชิก/ผู้เดินทาง
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending'); // pending | paid
            $table->string('pay_token', 32)->unique(); // ลิงก์จ่ายสาธารณะ /pay-share/{token}
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_ref')->nullable();
            $table->string('slip_path')->nullable();
            $table->dateTime('transfer_datetime')->nullable();
            $table->string('slip_ocr_status', 20)->nullable();
            $table->json('slip_ocr_result')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();

            $table->index(['booking_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_split_shares');
    }
};
