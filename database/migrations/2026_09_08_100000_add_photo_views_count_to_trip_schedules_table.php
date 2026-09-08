<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * จำนวนคนที่เข้าดูอัลบั้มรูปประจำรอบ — นับจากหน้า /album/{token} เท่านั้น
     * (คนละตัวกับ trips.views_count ที่นับคนดูหน้าทริป)
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->unsignedBigInteger('photo_views_count')->default(0)->after('photo_token');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('photo_views_count');
        });
    }
};
