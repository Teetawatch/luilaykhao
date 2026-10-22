<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            // หมุดจุดรับรายคน — คนในใบเดียวกันขึ้นรถคนละที่ได้ แม้ไม่มีจุดรับตายตัวรองรับ
            // มีค่า = ชนะทั้งจุดรับรายคนและหมุด/จุดของใบจอง (ดู BookingPassenger::ownPickupPin)
            $table->string('custom_pickup_label')->nullable()->after('pickup_point_id');
            $table->decimal('custom_pickup_lat', 10, 7)->nullable()->after('custom_pickup_label');
            $table->decimal('custom_pickup_lng', 10, 7)->nullable()->after('custom_pickup_lat');
            $table->text('custom_pickup_note')->nullable()->after('custom_pickup_lng');
        });
    }

    public function down(): void
    {
        Schema::table('booking_passengers', function (Blueprint $table) {
            $table->dropColumn([
                'custom_pickup_label',
                'custom_pickup_lat',
                'custom_pickup_lng',
                'custom_pickup_note',
            ]);
        });
    }
};
