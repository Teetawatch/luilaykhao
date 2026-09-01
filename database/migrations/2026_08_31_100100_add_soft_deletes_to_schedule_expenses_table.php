<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เงินที่ลบไปแล้วต้องยังตามรอยได้ — เดิมลบทิ้งจริงพร้อมสลิป ไม่เหลือหลักฐาน
        Schema::table('schedule_expenses', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
