<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * บันทึกไว้บนใบจองว่าจองในแคมเปญไหน และแคมเปญลดไปเท่าไหร่ — หน้างบ/กำไร
     * ถึงจะตอบได้ว่า 9.9 สร้างยอดเท่าไหร่และเสียส่วนลดไปเท่าไหร่ ราคาบนใบจอง
     * ถูกล็อกไว้ตอนจองอยู่แล้ว คอลัมน์นี้เป็นข้อมูลประกอบล้วน ๆ
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('sale_campaign_id')->nullable()->after('promotion_code')
                ->constrained('sale_campaigns')->nullOnDelete();
            $table->decimal('campaign_discount', 10, 2)->default(0)->after('sale_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_campaign_id');
            $table->dropColumn('campaign_discount');
        });
    }
};
