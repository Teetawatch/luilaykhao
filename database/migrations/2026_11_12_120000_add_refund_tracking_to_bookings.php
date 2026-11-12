<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ติดตามการคืนเงินทีละขั้นให้ลูกค้าเห็นในแอป (RefundService)
 *
 * refund_status: awaiting_account → requested → processing → refunded
 * แต่ละขั้นมีเวลาของตัวเองไว้วาดไทม์ไลน์ และ refund_note คือข้อความจากทีมงาน
 * (เช่น "เลขบัญชีไม่ถูกต้อง กรุณากรอกใหม่")
 *
 * refund_full = เราเป็นฝ่ายยกเลิก (ทริปไม่ออก) จึงคืนทุกบาทรวมมัดจำ ไม่ใช่นโยบาย 80/50/0
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('refund_opened_at')->nullable()->after('refund_amount');
            $table->timestamp('refund_account_at')->nullable()->after('refund_opened_at');
            $table->timestamp('refund_processing_at')->nullable()->after('refund_account_at');
            $table->string('refund_note', 500)->nullable()->after('refund_processing_at');
            $table->boolean('refund_full')->default(false)->after('refund_note');
            $table->index('refund_status');
        });

        // ใบเก่าที่ระบบคืนให้เอง (รอบคนไม่ครบเลยกำหนด) ไม่มีเลขบัญชี — เดิมรอทีมงานโทรขอ
        // ตอนนี้ลูกค้ากรอกเองในแอปได้ จึงย้ายไปขั้น "รอบัญชี"
        DB::table('bookings')
            ->where('refund_status', 'requested')
            ->whereNull('refund_account')
            ->update(['refund_status' => 'awaiting_account']);

        DB::table('bookings')
            ->whereIn('refund_status', ['awaiting_account', 'requested'])
            ->whereNull('refund_opened_at')
            ->update(['refund_opened_at' => DB::raw('cancelled_at')]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['refund_status']);
            $table->dropColumn(['refund_opened_at', 'refund_account_at', 'refund_processing_at', 'refund_note', 'refund_full']);
        });
    }
};
