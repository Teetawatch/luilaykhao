<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            // ครั้งล่าสุดที่ทีมงานกด "ชวนช่วยกันเปิดรอบ" หาผู้ที่จองรอบนี้แล้ว
            // ใช้กันส่งซ้ำถี่ ๆ และแสดงบนเรดาร์รอบเสี่ยงว่าเคยชวนไปหรือยัง
            $table->timestamp('rally_nudged_at')->nullable()->after('photo_token');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('rally_nudged_at');
        });
    }
};
