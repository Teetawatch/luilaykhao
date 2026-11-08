<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // ชวนเช่าอุปกรณ์วันสุดท้ายที่ยังสั่งได้ — ส่งครั้งเดียวต่อใบ (SendRentalOrderRemindersJob)
            $table->timestamp('rental_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('rental_reminder_sent_at');
        });
    }
};
