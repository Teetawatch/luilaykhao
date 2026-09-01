<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ค่าตอบแทนต่อวันของทีมงาน — ใช้ลงรายการค่าจ้างให้อัตโนมัติตอนปิดงบรอบ
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('staff_day_rate', 10, 2)->nullable()->after('driver_pin_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('staff_day_rate');
        });
    }
};
