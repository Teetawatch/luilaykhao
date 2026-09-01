<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "ปิดงบรอบ" — จุดที่ตัวเลขของรอบนี้หยุดขยับ หลังจากนี้แก้ได้เฉพาะแอดมิน
        // และทุกการแก้ต้องมีเหตุผลลงบันทึกไว้ (schedule_expense_audits)
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->timestamp('finance_closed_at')->nullable()->after('photo_token');
            $table->foreignId('finance_closed_by')->nullable()->after('finance_closed_at')
                ->constrained('users')->nullOnDelete();
            // งบค่าใช้จ่ายที่ตั้งไว้ต่อรอบ — ว่าง = ใช้ผลรวมของรายการประจำที่เปิดใช้อยู่
            $table->decimal('finance_budget', 10, 2)->nullable()->after('finance_closed_by');

            $table->index('finance_closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropIndex(['finance_closed_at']);
            $table->dropConstrainedForeignId('finance_closed_by');
            $table->dropColumn(['finance_closed_at', 'finance_budget']);
        });
    }
};
