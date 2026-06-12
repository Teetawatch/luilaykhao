<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            // วัน-เวลาออกเดินทางจริง อาจอยู่ก่อน departure_date (วันทริป) เช่น
            // ทริปวันเสาร์ที่ 13 แต่รถออกคืนวันศุกร์ที่ 12 เวลา 23:30
            $table->dateTime('departs_at')->nullable()->after('departure_date');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('departs_at');
        });
    }
};
