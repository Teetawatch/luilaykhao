<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ให้คูปองสะสมแต้มยืนอยู่ได้เองโดยไม่ต้องผูกกับของรางวัล
 *
 * เดิม loyalty_redemptions บังคับ reward_id เพราะออกได้ทางเดียวคือแลกด้วยแต้ม
 * ส่วนลดวันเกิดไม่มีของรางวัลรองรับ จึงต้องให้ผูกของรางวัลแบบไม่บังคับ และเก็บ
 * มูลค่าส่วนลดไว้บนตัวคูปองเองได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->foreignId('reward_id')->nullable()->change();
            // ที่มาของคูปอง — แยกคูปองแลกแต้มออกจากของขวัญวันเกิด เพื่อให้รายงาน
            // และการออกซ้ำรายปีตรวจสอบได้
            $table->string('source', 20)->default('reward')->after('reward_id');
            // มูลค่าส่วนลดบนตัวคูปอง ใช้เมื่อไม่มีของรางวัลให้อ้างอิง
            $table->decimal('discount_value', 10, 2)->nullable()->after('points_used');
            $table->index(['user_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_redemptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source']);
            $table->dropColumn(['source', 'discount_value']);
        });
    }
};
