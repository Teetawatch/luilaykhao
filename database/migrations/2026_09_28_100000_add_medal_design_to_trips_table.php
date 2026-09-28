<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หน้าตาเหรียญพิชิตของทริป — "แบบผสม": ทุกทริปมีเหรียญแม่แบบให้ทันที
 * (ชื่อ + ไอคอน + สี ซึ่งเว้นว่างได้ทั้งหมด ระบบเดาค่าให้) และแอดมินอัปโหลด
 * ภาพเหรียญที่ออกแบบเองทับได้เฉพาะทริปเด่น ดู App\Support\MedalDesign
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('medal_name', 60)->nullable()->after('elevation_gain_m');
            $table->string('medal_icon', 40)->nullable()->after('medal_name');
            $table->string('medal_color', 7)->nullable()->after('medal_icon');
            $table->string('medal_image')->nullable()->after('medal_color');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['medal_name', 'medal_icon', 'medal_color', 'medal_image']);
        });
    }
};
