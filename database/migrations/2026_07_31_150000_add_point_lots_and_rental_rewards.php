<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แต้มมีวันหมดอายุ + ของรางวัลชนิดใหม่ "เช่าอุปกรณ์ฟรี"
 *
 * แต้มถูกเก็บเป็น "ล็อต" — แถว earn แต่ละแถวถือแต้มคงเหลือของตัวเอง
 * (`points_remaining`) และวันหมดอายุของตัวเอง เวลาแลกของรางวัลจะตัดจากล็อตที่
 * ใกล้หมดอายุที่สุดก่อน ทำให้รู้ได้ว่าแต้มก้อนไหนหมดอายุเมื่อไหร่ และเตือนล่วงหน้าได้
 *
 * ค่าเริ่มต้น `points_remaining = 0` ตั้งใจให้ปลอดภัย — แถวเก่าจะยังไม่ถูกหักหรือ
 * หมดอายุจนกว่าจะรัน `loyalty:backfill` ซึ่งเป็นตัวเกลี่ยแต้มคงเหลือลงล็อตให้ตรงกับ
 * ยอดในบัญชี และตั้งวันหมดอายุแบบมีระยะผ่อนผัน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->unsignedInteger('points_remaining')->default(0)->after('points');
            $table->timestamp('expires_at')->nullable()->after('balance_after');
            $table->index(['expires_at', 'points_remaining']);
        });

        // enum เดิมรับแค่ 3 ชนิด ค่าใหม่จะถูกปฏิเสธ จึงต้องเปลี่ยนเป็น string ก่อน
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->string('type', 30)->default('discount_fixed')->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropIndex(['expires_at', 'points_remaining']);
            $table->dropColumn(['points_remaining', 'expires_at']);
        });

        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->enum('type', ['discount_percent', 'discount_fixed', 'free_item'])->change();
        });
    }
};
