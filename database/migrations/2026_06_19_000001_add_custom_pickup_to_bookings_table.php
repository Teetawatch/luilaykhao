<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // จุดรับที่ลูกค้าปักหมุดเอง (อยู่ในเส้นทางผ่านที่รับได้) — ต้องรอแอดมินยืนยันราคา
            $table->string('custom_pickup_label')->nullable()->after('pickup_point_id');
            $table->decimal('custom_pickup_lat', 10, 7)->nullable()->after('custom_pickup_label');
            $table->decimal('custom_pickup_lng', 10, 7)->nullable()->after('custom_pickup_lat');
            $table->text('custom_pickup_note')->nullable()->after('custom_pickup_lng');
            // pending | approved | rejected — null = ไม่ได้ใช้จุดรับแบบ custom
            $table->string('custom_pickup_status')->nullable()->after('custom_pickup_note');
            // ราคาจุดรับที่แอดมินตั้งตอนอนุมัติ (บวกเข้า total_amount)
            $table->decimal('custom_pickup_price', 10, 2)->nullable()->after('custom_pickup_status');
            $table->text('custom_pickup_reject_reason')->nullable()->after('custom_pickup_price');
            $table->timestamp('custom_pickup_resolved_at')->nullable()->after('custom_pickup_reject_reason');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'custom_pickup_label',
                'custom_pickup_lat',
                'custom_pickup_lng',
                'custom_pickup_note',
                'custom_pickup_status',
                'custom_pickup_price',
                'custom_pickup_reject_reason',
                'custom_pickup_resolved_at',
            ]);
        });
    }
};
