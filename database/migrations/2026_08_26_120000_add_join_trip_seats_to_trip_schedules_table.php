<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * เพดานจำนวนคนจอยทริป + ตัวนับที่จองไปแล้ว
     *
     * ก่อนหน้านี้จอยทริปรับได้ไม่จำกัด (ไม่กินที่นั่งรถ booked_seats จึงไม่นับให้)
     * แต่หน้างานรับได้จำกัดจริง ๆ — แอดมินจึงต้องกำหนดเพดานได้ และลูกค้าต้องเห็นว่า
     * เหลือกี่ที่ join_trip_seats = null คือ "ไม่จำกัด" เหมือนพฤติกรรมเดิม
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->unsignedInteger('join_trip_seats')->nullable()->after('join_trip_price');
            $table->unsignedInteger('join_trip_booked_seats')->default(0)->after('join_trip_seats');
        });

        // เติมตัวนับให้รอบที่มีคนจอยอยู่แล้ว ไม่งั้นหน้าเว็บจะขึ้น "จองแล้ว 0 คน"
        // จนกว่าจะมีใครไปแตะรอบนั้นให้ syncBookedSeats() ทำงาน
        $counts = DB::table('booking_passengers')
            ->join('bookings', 'bookings.id', '=', 'booking_passengers.booking_id')
            ->where('bookings.is_join_trip', true)
            ->whereIn('bookings.status', ['pending', 'confirmed'])
            ->whereNotNull('bookings.schedule_id')
            ->groupBy('bookings.schedule_id')
            ->select('bookings.schedule_id', DB::raw('count(*) as passenger_count'))
            ->pluck('passenger_count', 'schedule_id');

        foreach ($counts as $scheduleId => $count) {
            DB::table('trip_schedules')
                ->where('id', $scheduleId)
                ->update(['join_trip_booked_seats' => (int) $count]);
        }
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['join_trip_seats', 'join_trip_booked_seats']);
        });
    }
};
