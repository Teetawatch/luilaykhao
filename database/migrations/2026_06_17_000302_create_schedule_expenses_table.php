<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการค่าใช้จ่ายจริงต่อรอบเดินทาง — นำไปหักลบจากเงินที่รับจริงเพื่อหากำไรต่อรอบ
        Schema::create('schedule_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            // อ้างที่มาจากรายการประจำ (ถ้ามี) — ลบ template แล้วประวัติยังอยู่
            $table->foreignId('expense_template_id')->nullable()->constrained('expense_templates')->nullOnDelete();
            // snapshot ชื่อ ณ ตอนบันทึก เพื่อให้การ rename/ลบ template ไม่กระทบประวัติ
            $table->string('name');
            $table->decimal('amount', 10, 2)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_expenses');
    }
};
