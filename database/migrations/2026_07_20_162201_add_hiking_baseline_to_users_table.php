<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ค่าอ้างอิงที่ผู้ใช้กรอกเอง — ใช้ประเมิน "ทริปนี้ไหวไหม" สำหรับคนที่ยังไม่เคย
 * เดินทางกับเรา จึงยังไม่มีประวัติใน Passport ให้เทียบ
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('self_reported_max_distance_km', 6, 1)->nullable()->after('health_notes');
            $table->unsignedInteger('self_reported_max_elevation_m')->nullable()->after('self_reported_max_distance_km');
            $table->timestamp('hiking_baseline_updated_at')->nullable()->after('self_reported_max_elevation_m');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'self_reported_max_distance_km',
                'self_reported_max_elevation_m',
                'hiking_baseline_updated_at',
            ]);
        });
    }
};
