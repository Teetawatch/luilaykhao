<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ใช้แต้มสะสมลดค่าทริปตอนจอง (ระบบแต้มเฟส 2)
 *
 * - bookings.points_used      แต้มที่หักจากใบจองนี้
 * - bookings.points_discount  ยอดบาทที่แต้มเหล่านั้นลดให้ (รวมอยู่ใน discount_amount แล้ว
 *                             เหมือน coins_used) เก็บแยกเพราะอัตราแลกแต้มเปลี่ยนได้ใน config
 * - loyalty_transactions.meta ล็อตที่ถูกตัดไปพร้อมวันหมดอายุเดิม — ยกเลิกใบจองแล้วคืน
 *                             แต้มกลับเป็นล็อตเดิม ไม่ใช่ได้อายุใหม่ 24 เดือน
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('points_used')->default(0)->after('coins_used');
            $table->decimal('points_discount', 10, 2)->default(0)->after('points_used');
        });

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['points_used', 'points_discount']);
        });
    }
};
