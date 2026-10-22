<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * พาร์ทเนอร์ช่วยขาย — คนนอกแชร์ลิงก์/โค้ดของตัวเอง ลูกค้าจ่ายเงินเข้าบริษัทตรง
 * แล้วพาร์ทเนอร์ได้ค่าคอมเมื่อทริปเดินทางจริง
 *
 * ผูกพาร์ทเนอร์กับ "ใบจอง" ไม่ใช่กับตัวลูกค้า (ต่างจากระบบชวนเพื่อนที่ผูกตอนสมัคร)
 * ลูกค้าคนเดียวกันจองครั้งหน้าผ่านลิงก์ของอีกคน คอมก็ไปที่อีกคน
 *
 * partner_commissions หนึ่งแถวต่อใบจอง (+ แถว clawback เมื่อใบที่จ่ายคอมไปแล้ว
 * ถูกคืนเงินทีหลัง ไปหักจากรอบจ่ายถัดไป) · partner_payouts คือการโอนแต่ละครั้ง
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 20)->unique();
            $table->string('display_name', 100);
            // percent = % ของค่าทริป · per_seat = บาทต่อที่นั่ง
            $table->string('commission_type', 10)->default('percent');
            $table->decimal('commission_rate', 10, 2);
            $table->string('status', 20)->default('active');
            // {bank, number, name} — เข้ารหัส (cast encrypted:array)
            $table->text('payout_account')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('sale_campaign_id')->constrained('partners')->nullOnDelete();
            // link = เปิดจากลิงก์ · code = พิมพ์โค้ดเอง · admin = ทีมงานผูกให้
            $table->string('partner_source', 10)->nullable()->after('partner_id');
        });

        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('commission_count')->default(0);
            $table->timestamp('paid_at');
            $table->string('reference', 100)->nullable();
            $table->string('slip_path')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('paid_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            // ใบจองอาจถูกลบถาวรทีหลัง ประวัติการจ่ายต้องอยู่ต่อ — จึงแช่ ref/ชื่อทริปไว้
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('booking_ref', 30);
            $table->string('trip_title')->nullable();
            $table->date('departure_date')->nullable();
            $table->string('type', 10)->default('commission');
            $table->string('status', 10)->default('pending');
            $table->unsignedInteger('seats')->default(0);
            $table->decimal('base_amount', 12, 2)->default(0);
            // อัตรา ณ วันที่ยืนยันใบจอง — แก้อัตราพาร์ทเนอร์ทีหลังไม่ย้อนมาเปลี่ยนใบเก่า
            $table->string('rate_type', 10);
            $table->decimal('rate', 10, 2);
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('payout_id')->nullable()->constrained('partner_payouts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['booking_id', 'type']);
            $table->index(['partner_id', 'status']);
            $table->index(['status', 'departure_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_commissions');
        Schema::dropIfExists('partner_payouts');

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn('partner_source');
        });

        Schema::dropIfExists('partners');
    }
};
