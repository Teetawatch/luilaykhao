<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * หลักฐานความยินยอม — ฟอร์มบังคับติ๊กอยู่แล้วแต่ไม่เคยเก็บไว้ว่าติ๊กเมื่อไหร่
     *
     * ข้อมูลที่หน้านี้รับมีเลขบัตรประชาชน อาหารที่แพ้ และโรคประจำตัว ซึ่งเป็น
     * ข้อมูลอ่อนไหวตาม PDPA ม.26 ที่ต้องได้ความยินยอมโดยชัดแจ้ง และต้องพิสูจน์
     * ย้อนหลังได้ เก็บข้อความที่แสดงตอนนั้นไว้ด้วย เพราะข้อความอาจถูกแก้ทีหลัง
     */
    public function up(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable()->after('halal_food');
            $table->string('consent_ip', 45)->nullable()->after('consent_at');
            $table->text('consent_text')->nullable()->after('consent_ip');
        });
    }

    public function down(): void
    {
        Schema::table('customer_intake_people', function (Blueprint $table) {
            $table->dropColumn(['consent_at', 'consent_ip', 'consent_text']);
        });
    }
};
