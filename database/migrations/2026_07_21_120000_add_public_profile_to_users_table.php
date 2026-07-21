<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ชื่อผู้ใช้สำหรับลิงก์สาธารณะ /u/{handle} — จองไว้ครั้งเดียวแล้วคงที่
            $table->string('public_handle', 30)->nullable()->unique()->after('nickname');
            // ปิดไว้เป็นค่าเริ่มต้น: โปรไฟล์เป็นสาธารณะเมื่อเจ้าตัวเปิดเองเท่านั้น
            $table->boolean('public_profile_enabled')->default(false)->after('public_handle');
            // ข้อความแนะนำตัวสั้น ๆ ที่แสดงบนโปรไฟล์สาธารณะ
            $table->string('public_bio', 160)->nullable()->after('public_profile_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['public_handle', 'public_profile_enabled', 'public_bio']);
        });
    }
};
