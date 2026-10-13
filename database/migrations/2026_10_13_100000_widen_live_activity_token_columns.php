<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * token ของ Live Activity ยาวเกิน 200 ตัวอักษรได้จริง — Apple ไม่ได้กำหนด
     * ความยาวไว้ และ push-to-start token บน iOS รุ่นใหม่ยาวกว่าที่เราเผื่อไว้
     * ตอนนั้นคำขอบันทึก FCM token ทั้งก้อนถูกตีกลับ 422 เครื่องนั้นจึงไม่ได้
     * รับแจ้งเตือนอะไรเลย ขยายเป็น 512 เท่ากับ fcm_tokens.token
     */
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->string('live_activity_start_token', 512)->nullable()->change();
        });

        Schema::table('live_activities', function (Blueprint $table) {
            $table->string('push_token', 512)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->string('live_activity_start_token', 200)->nullable()->change();
        });

        Schema::table('live_activities', function (Blueprint $table) {
            $table->string('push_token', 200)->change();
        });
    }
};
