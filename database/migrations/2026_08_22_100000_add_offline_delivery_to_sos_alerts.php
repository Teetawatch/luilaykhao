<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SOS ที่ส่งจากที่ไม่มีสัญญาณ
     *
     * เดิมเวลาของเคสคือ created_at = เวลาที่ "เซิร์ฟเวอร์รับรู้" ซึ่งเท่ากับเวลา
     * ที่เกิดเหตุก็ต่อเมื่อคนกดมีเน็ตตอนนั้น บนดอยไม่เป็นแบบนั้น — สัญญาณกลับมา
     * ตอนรถลงถึงตีนดอยสองชั่วโมงให้หลัง ทีมค้นหาจึงต้องรู้ว่า "กดตอนกี่โมง"
     * ไม่ใช่ "ระบบได้รับตอนกี่โมง" เพราะสองค่านี้ต่างกันได้เป็นชั่วโมง
     *
     * client_token คือกุญแจกัน SOS ซ้ำข้ามการรีสตาร์ทแอป: คิวที่ค้างอยู่ในเครื่อง
     * อาจถูกส่งซ้ำหลังผู้ใช้ปิดแอปแล้วเปิดใหม่ ซึ่งกลไกกันซ้ำเดิม (ดูใน 2 นาที
     * ล่าสุด) ครอบไม่ถึง
     */
    public function up(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('contact_phone');
            $table->string('client_token', 64)->nullable()->unique()->after('occurred_at');
            $table->string('source', 16)->default('app')->after('client_token');
        });
    }

    public function down(): void
    {
        Schema::table('sos_alerts', function (Blueprint $table) {
            $table->dropUnique(['client_token']);
            $table->dropColumn(['occurred_at', 'client_token', 'source']);
        });
    }
};
