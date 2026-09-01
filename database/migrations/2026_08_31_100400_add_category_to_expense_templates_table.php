<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการประจำต้องพก "หมวด" มาด้วย ไม่งั้นแถวที่ดึงเข้ารอบจะไม่มีหมวด
        // แล้วไปติดเงื่อนไขปิดงบของโหมดเข้มงวดทุกครั้ง
        Schema::table('expense_templates', function (Blueprint $table) {
            $table->string('category', 32)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('expense_templates', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
