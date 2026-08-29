<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ที่นั่ง A1 ของรถบัสกับ A1 ของรถตู้เป็นคนละที่นั่ง
     *
     * เดิมตัวตนของที่นั่งคือ (รอบ, รหัสที่นั่ง) ซึ่งพอรอบเดียววิ่งสองคันก็แปลว่า
     * คนที่จอง A1 บนบัสไปแล้วทำให้ A1 บนตู้จองไม่ได้ตามไปด้วย — คันที่สองจึงเคย
     * ต้องเป็นแบบไม่ระบุที่นั่ง ตอนนี้ "คันไหน" เข้ามาเป็นส่วนหนึ่งของตัวตนแล้ว
     *
     * ใช้ 0 แทน NULL สำหรับรอบที่ไม่มีตัวเลือก (= รอบนี้มีรถคันเดียว) เพราะ UNIQUE
     * ที่มีคอลัมน์ NULL ไม่บังคับความซ้ำให้กับแถวที่เป็น NULL ทั้ง MySQL และ Postgres
     * — ที่นั่งของรอบธรรมดาจะกลายเป็นจองซ้อนกันได้เงียบ ๆ ด้วยเหตุนี้จึงไม่ผูก FK
     * (0 ไม่ใช่แถวจริง) และการลบตัวเลือกที่มีคนจองอยู่ถูกกันไว้แล้วที่ชั้นแอดมิน
     */
    public function up(): void
    {
        // เขียนแบบทนการรันซ้ำ — MySQL ไม่ทำ DDL ในธุรกรรม ไมเกรชันที่ล้มกลางทาง
        // จะทิ้งคอลัมน์ที่เพิ่มไปแล้วค้างไว้ แล้วรันรอบถัดไปไม่ผ่านอีกเลย
        if (! Schema::hasColumn('booking_seats', 'vehicle_option_id')) {
            Schema::table('booking_seats', function (Blueprint $table) {
                $table->unsignedBigInteger('vehicle_option_id')->default(0)->after('schedule_id');
            });
        }

        // ใบจองที่เลือกคันไว้แล้ว (จองก่อนไมเกรชันนี้ ตอนที่คันรองยังไม่มีผัง)
        // ย้ายที่นั่งไปอยู่ใต้คันของตัวเอง
        // เดินทีละใบแทน UPDATE...JOIN — sqlite (ฐานข้อมูลของเทสต์) ไม่รองรับ และ
        // ใบที่เข้าเงื่อนไขมีน้อยมาก (ฟีเจอร์เลือกคันเพิ่งเปิด)
        DB::table('bookings')
            ->whereNotNull('vehicle_option_id')
            ->select('id', 'vehicle_option_id')
            ->orderBy('id')
            ->each(function ($booking) {
                DB::table('booking_seats')
                    ->where('booking_id', $booking->id)
                    ->update(['vehicle_option_id' => $booking->vehicle_option_id]);
            });

        $indexes = collect(Schema::getIndexes('booking_seats'))->pluck('name');

        // สร้างดัชนีใหม่ก่อนแล้วค่อยทิ้งของเดิม — MySQL ใช้ดัชนีเดิมค้ำ foreign key
        // ของ schedule_id อยู่ ทิ้งก่อนจะโดนปฏิเสธ (ดัชนีใหม่ขึ้นต้นด้วย schedule_id
        // เหมือนกัน จึงรับหน้าที่ค้ำ FK ต่อได้)
        if (! $indexes->contains('booking_seats_schedule_id_vehicle_option_id_seat_id_unique')) {
            Schema::table('booking_seats', function (Blueprint $table) {
                $table->unique(['schedule_id', 'vehicle_option_id', 'seat_id']);
            });
        }

        if ($indexes->contains('booking_seats_schedule_id_seat_id_unique')) {
            Schema::table('booking_seats', function (Blueprint $table) {
                $table->dropUnique(['schedule_id', 'seat_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('booking_seats', function (Blueprint $table) {
            $table->unique(['schedule_id', 'seat_id']);
        });

        Schema::table('booking_seats', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'vehicle_option_id', 'seat_id']);
            $table->dropColumn('vehicle_option_id');
        });
    }
};
