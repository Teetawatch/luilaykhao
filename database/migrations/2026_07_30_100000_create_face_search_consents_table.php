<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * บันทึกความยินยอมตาม PDPA ก่อนใช้ "ค้นหารูปของฉันด้วยใบหน้า" ในอัลบั้มสาธารณะ
     *
     * ตารางนี้เก็บ "หลักฐานการขอความยินยอม" เท่านั้น — ภาพใบหน้าและเวกเตอร์ใบหน้า
     * ถูกประมวลผลบนเครื่องของลูกค้าทั้งหมด ไม่มีการส่งเข้ามาเก็บที่เซิร์ฟเวอร์
     * (PDPA ม.26 ข้อมูลชีวมาตรต้องได้รับความยินยอมโดยชัดแจ้ง + ม.19 ต้องพิสูจน์ได้)
     */
    public function up(): void
    {
        Schema::create('face_search_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_schedule_id')->nullable()->constrained()->nullOnDelete();
            // token ที่ใช้ตอนให้ความยินยอม — เก็บไว้แม้รอบเดินทางถูกลบ เพื่อสืบย้อนได้
            $table->string('photo_token', 64)->index();
            // รหัสสุ่มฝั่งเบราว์เซอร์ (ไม่ผูกกับตัวตน) ใช้จับคู่ตอนถอนความยินยอม
            $table->uuid('subject_key')->index();
            $table->string('consent_version', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('consented_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // ยินยอมซ้ำจากเครื่องเดิมในอัลบั้มเดิม = อัปเดตแถวเดิม ไม่สร้างใหม่
            $table->unique(['photo_token', 'subject_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_search_consents');
    }
};
