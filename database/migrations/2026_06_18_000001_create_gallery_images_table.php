<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * แกลเลอรีภาพประทับใจ — รูปที่แอดมินคัดเลือกเองมาโชว์บนหน้าเว็บหลัก (/gallery)
 * เก็บเป็น URL เต็ม (อัปโหลดขึ้น R2 ผ่าน /admin/upload-image เหมือน hero slides)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_images', function (Blueprint $table) {
            $table->id();
            $table->string('image_url');
            $table->string('caption')->nullable();
            $table->string('location')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_images');
    }
};
