<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ข้อมูลเที่ยวบินและจุดนัดพบของรอบที่บินไป
     *
     * ทริปต่างประเทศไม่มีจุดขึ้นรถ (นัดเจอกันที่สนามบิน) แต่ก่อนหน้านี้ไม่มีที่เก็บ
     * "ไปที่ไหน กี่โมง" มาแทน — ข้อมูลนี้เลยไปกองอยู่ในกำหนดการซึ่งเป็นข้อความ
     * ยาว ๆ อ่านยากบนมือถือ และการ์ด "วันเดินทาง" ก็หยิบไปใช้ไม่ได้เพราะไม่รู้ว่า
     * ตรงไหนคือเวลานัดพบ
     *
     * เก็บที่ระดับรอบ (ไม่ใช่ระดับทริป) เพราะแต่ละรอบบินคนละไฟลต์คนละเวลา
     *
     * `flights` เป็น JSON แถวละหนึ่งขา ({direction, airline, flight_no, from, to,
     * depart_at, arrive_at}) ตามแนวเดียวกับ itinerary/must_know/faqs ของทริป —
     * จำนวนขาต่อรอบไม่คงที่ (บินตรง/ต่อเครื่อง/บินกลับคนละสายการบิน) และไม่มีใคร
     * ต้อง query หาไฟลต์ข้ามรอบ จึงไม่คุ้มที่จะแตกเป็นตารางแยก
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->string('meeting_point')->nullable()->after('transport_type');
            $table->string('meeting_map_url', 500)->nullable()->after('meeting_point');
            // เวลานัดพบเป็นเวลาไทยเสมอ (นัดเจอกันที่สนามบินต้นทางในไทย) —
            // เก็บเป็น string HH:MM เหมือน schedule_pickup_points.pickup_time
            // จะได้ไม่ต้องไปเจอกับดัก timezone ของคอลัมน์ประเภทเวลา
            $table->string('meeting_time', 5)->nullable()->after('meeting_map_url');
            $table->string('baggage_allowance')->nullable()->after('meeting_time');
            $table->json('flights')->nullable()->after('baggage_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn([
                'meeting_point',
                'meeting_map_url',
                'meeting_time',
                'baggage_allowance',
                'flights',
            ]);
        });
    }
};
