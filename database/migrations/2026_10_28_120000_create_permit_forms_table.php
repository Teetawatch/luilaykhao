<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แบบฟอร์มขออนุญาตของอุทยาน/หน่วยงาน ที่ต้องกรอกต่อผู้เดินทางหนึ่งคน
 *
 * `pages` คือภาพของแต่ละหน้าที่หน้าแอดมินแปลงจาก PDF ต้นฉบับไว้ตอนอัปโหลด
 * (ตัวอ่าน PDF ฝั่งเซิร์ฟเวอร์ที่ใช้ได้ฟรีเปิดไฟล์บีบอัดแบบใหม่ไม่ได้ เช่นไฟล์ของ
 * อุทยานแห่งชาติทองผาภูมิ จึงเก็บเป็นภาพแล้ววาดทับแทน) ส่วน `fields` คือช่องที่
 * วางไว้บนภาพ ตำแหน่งเป็นสัดส่วน 0–1 ของหน้า ดู PermitFormService
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permit_forms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('note', 500)->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_name')->nullable();
            $table->json('pages');
            $table->json('fields')->nullable();
            $table->timestamps();
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('permit_form_id')->nullable()->after('meal_plan')
                ->constrained('permit_forms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('permit_form_id');
        });

        Schema::dropIfExists('permit_forms');
    }
};
