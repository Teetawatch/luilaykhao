<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * เอกสารแนบที่ทริปต้องใช้ — แอดมินเป็นคนกำหนดเองว่าทริปไหนต้องใช้อะไรบ้าง
 *
 * เก็บเป็น JSON บนทริปเหมือน `faqs`/`rental_items` เพราะเป็นรายการสั้น ๆ ที่
 * แก้พร้อมกันทั้งชุดในหน้าแก้ทริป ไม่เคยถูก query แยกรายแถว
 *
 * โครงหนึ่งรายการ: { key, label, note, required }
 * - `key`  รหัสคงที่ ใช้ผูกไฟล์ที่อัปโหลดกับข้อกำหนด แม้แอดมินจะแก้ชื่อทีหลัง
 * - `note` คือช่อง "ใช้สำหรับ..........." ที่แอดมินพิมพ์เองได้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->json('document_requirements')->nullable()->after('rental_items');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('document_requirements');
        });
    }
};
