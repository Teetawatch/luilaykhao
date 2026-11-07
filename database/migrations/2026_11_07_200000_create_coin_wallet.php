<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * กระเป๋า Coin — รางวัลจากการรีวิว (ข้อความยาว/แนบรูป/แนบวิดีโอ) ใช้หักค่าทริป
 * ตอนจอง 1 Coin = 1 บาท แยกจากแต้มสะสม (loyalty) ซึ่งเป็นอีกสกุลหนึ่ง
 *
 * - users.coin_balance  ยอดคงเหลือ (ตัวเลขเดียวที่ตัดสินว่าใช้ได้เท่าไร)
 * - coin_transactions   สมุดบัญชี ทุกการเปลี่ยนยอดมีแถวเสมอ
 * - reviews.tags        ปุ่มคำรีวิวที่ลูกค้ากดเลือก (เก็บแยกจาก comment ที่พิมพ์เอง)
 * - reviews.coins_awarded  Coin ที่รีวิวนี้ได้ไปแล้ว (แสดงผล — ตัวกันซ้ำจริงคือสมุด)
 * - bookings.coins_used ยอด Coin ที่หักจากใบจองนี้ (รวมอยู่ใน discount_amount แล้ว)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('coin_balance')->default(0);
        });

        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // review | review_revoke | spend | restore | adjust
            $table->string('type', 20);
            $table->integer('coins');
            $table->unsignedInteger('balance_after');
            $table->string('description');
            $table->nullableMorphs('reference');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('comment');
            $table->unsignedSmallInteger('coins_awarded')->default(0)->after('videos');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('coins_used')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('coins_used');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['tags', 'coins_awarded']);
        });

        Schema::dropIfExists('coin_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('coin_balance');
        });
    }
};
