<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * push-to-start token ของ Live Activity (iOS 17.2+)
     *
     * ต่างจาก push_token ใน live_activities ตรงที่ตัวนี้เป็นของ "แอปบนเครื่องนี้"
     * ไม่ใช่ของ Activity ตัวใดตัวหนึ่ง — มันคือกุญแจที่ทำให้เซิร์ฟเวอร์ "เปิด"
     * Live Activity ขึ้นมาเองได้เช้าวันเดินทาง โดยลูกค้าไม่ต้องเปิดแอปเลย
     *
     * อยู่บน fcm_tokens เพราะเป็น token ระดับเครื่องเหมือนกัน มีวงจรชีวิตเดียวกัน
     * (ล้างตอน logout, ตายตอนถอนแอป) การแยกตารางจะได้แค่ตารางที่ต้องล้างพร้อมกัน
     */
    public function up(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->string('live_activity_start_token', 200)->nullable()->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('fcm_tokens', function (Blueprint $table) {
            $table->dropColumn('live_activity_start_token');
        });
    }
};
