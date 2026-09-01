<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ปูมบัญชีของรอบเดินทาง — ใครแก้ตัวเลขไหน จากเท่าไรเป็นเท่าไร เมื่อไหร่
        // เก็บ schedule_id แยกไว้ด้วย เพราะรายการอาจถูกลบภายหลังแต่ปูมต้องอยู่
        Schema::create('schedule_expense_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('schedule_expenses')->nullOnDelete();
            // created | updated | deleted | closed | reopened
            $table->string('action', 16);
            // snapshot ก่อน/หลัง เฉพาะฟิลด์ที่มีความหมายทางบัญชี
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            // เหตุผล — บังคับเมื่อแก้รอบที่ปิดงบไปแล้ว
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['schedule_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_expense_audits');
    }
};
