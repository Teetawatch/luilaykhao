<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_itinerary_items', function (Blueprint $table) {
            // เช็คอินจุดกำหนดการ: สตาฟกดยืนยันว่า "มาถึงจุดนี้แล้ว" — แชร์กันทั้งทีม
            // ของรอบนั้น (ใครกดก็เห็นเหมือนกัน) กันลืม/ผิดแผน
            $table->timestamp('reached_at')->nullable()->after('sort_order');
            $table->foreignId('reached_by')->nullable()->after('reached_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_itinerary_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reached_by');
            $table->dropColumn('reached_at');
        });
    }
};
