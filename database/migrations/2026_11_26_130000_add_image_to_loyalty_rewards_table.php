<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * รูปประจำของรางวัล — ลูกค้าบางคนดูแค่ไอคอนแล้วไม่รู้ว่าจะได้อะไร
 * เก็บเป็น URL จากคลังสื่อ (admin/upload-image) แบบรูปอื่น ๆ ของแอดมิน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->string('image', 2048)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
