<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คำแปลของเนื้อหาที่แอดมินกรอก (ทริป จุดรับ หมวดหมู่ ประกาศ …) — แถวละหนึ่ง
 * ข้อความไทยหนึ่งช่อง เช่น trip#12 "faqs.3.answer" ภาษา en
 *
 * source_hash คือ sha1 ของข้อความไทยตอนที่แปล: ข้อความไทยเปลี่ยนเมื่อไหร่ hash
 * ไม่ตรง แปลใหม่เฉพาะช่องนั้น และหาคำแปลเดิมที่ข้อความไทยตรงกันมาใช้ซ้ำได้
 * (จุดรับเดียวกันถูกก๊อปไปทุกรอบ)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_translations', function (Blueprint $table) {
            $table->id();
            // ชื่อย่อของชนิดเนื้อหา (trip, pickup-point, …) ไม่ใช่ชื่อคลาส — ย้ายคลาสแล้วไม่พัง
            $table->string('translatable_type', 40);
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale', 8);
            $table->string('path', 191);
            $table->char('source_hash', 40);
            $table->text('source_text');
            $table->text('text');
            // แอดมินแก้เอง — AI ไม่เขียนทับ ถ้าข้อความไทยเปลี่ยนทีหลังจะขึ้นเตือนแทน
            $table->boolean('is_manual')->default(false);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['translatable_type', 'translatable_id', 'locale', 'path'], 'content_translations_path_unique');
            $table->index(['locale', 'source_hash'], 'content_translations_hash_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_translations');
    }
};
