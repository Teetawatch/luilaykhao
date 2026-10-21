<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // สรุปยอดหน้างานของรอบ — การ์ดเก็บเงินหายไปพร้อมห้องแชท (ล้าง 3 วันหลังจบทริป)
        // จึงแช่ยอดไว้ที่นี่ก่อนล้าง ออฟฟิศยังตรวจรับเงินย้อนหลังได้
        Schema::create('onsite_money_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->unique()->constrained('trip_schedules')->cascadeOnDelete();
            $table->json('summary');
            $table->timestamp('frozen_at');
            $table->timestamps();
        });

        // ส่งมอบเงินหน้างาน: สตาฟกด "ส่งเงินแล้ว" (แช่ยอดที่ถือตอนนั้น) → การเงินกด "ได้รับแล้ว"
        // ยอดที่ถือเปลี่ยนหลังส่ง (เก็บเพิ่ม/ยกเลิก) = ต้องส่งใหม่
        Schema::create('onsite_money_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('cash_amount', 10, 2);
            $table->decimal('transfer_amount', 10, 2);
            $table->string('note', 300)->nullable();
            $table->timestamp('submitted_at');
            $table->foreignId('received_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('received_note', 300)->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onsite_money_handovers');
        Schema::dropIfExists('onsite_money_reports');
    }
};
