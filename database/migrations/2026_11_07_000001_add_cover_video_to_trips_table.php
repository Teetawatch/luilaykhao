<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * วิดีโอหน้าปก — คลิปสั้นที่แอปเล่นวนแบบปิดเสียงแทนรูปปกบนหน้ารายละเอียดทริป
 * เป็น URL เดียว (ปกติเลือกจากคลิปใน `videos`) ว่าง = ใช้รูปปกตามเดิม
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('cover_video', 1024)->nullable()->after('cover_image');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('cover_video');
        });
    }
};
