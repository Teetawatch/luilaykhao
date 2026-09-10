<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ลูกค้ากดยอมรับเงื่อนไขก่อนจองมาตลอด แต่ระบบไม่เคยเก็บหลักฐานว่ากดเมื่อไหร่
 * และยอมรับเงื่อนไข "ฉบับไหน" — พอเงื่อนไขถูกแก้ทีหลัง ก็ไม่มีอะไรบอกได้ว่า
 * ใบจองใบนั้นตกลงตามข้อความชุดใด ซึ่งเป็นสิ่งแรกที่ถูกถามเวลามีข้อพิพาท
 *
 * รูปแบบเดียวกับที่ระบบเก็บ consent อยู่แล้วใน face_search_consents และ
 * customer_intake_people.consent_proof
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // ว่าง = ใบจองที่สร้างผ่านช่องทางที่ยังไม่ได้ขอความยินยอม (ใบเก่า,
            // แอดมินจองแทน, แอปรุ่นก่อน) ไม่ใช่ "ไม่ยอมรับ" — จึงต้อง nullable
            $table->timestamp('terms_accepted_at')->nullable()->after('checked_in_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
