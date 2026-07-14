<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // อ้างอิงคนขับจาก "ทะเบียนคนขับ" (drivers) — เลือกครั้งเดียว ใช้ซ้ำได้ทุกคัน
            // ฟิลด์ driver_name/driver_phone/driver_photo ยังคงอยู่เป็น snapshot ให้โค้ดเดิมอ่านได้
            $table->foreignId('driver_id')->nullable()->after('color')
                ->constrained('drivers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
        });
    }
};
