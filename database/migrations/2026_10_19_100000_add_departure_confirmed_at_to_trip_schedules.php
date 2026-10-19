<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ทีมงานยืนยันว่ารอบนี้ออกเดินทางแม้คนยังไม่ครบขั้นต่ำ — ปลดข้อความการเดินทาง
 * (ใบเดินทาง / SMS เตือน / แจ้งเตือนก่อนวันเดินทาง) ที่ระงับไว้ระหว่างรอบยังไม่ครบ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->timestamp('departure_confirmed_at')->nullable();
            $table->foreignId('departure_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('departure_confirmed_by');
            $table->dropColumn('departure_confirmed_at');
        });
    }
};
