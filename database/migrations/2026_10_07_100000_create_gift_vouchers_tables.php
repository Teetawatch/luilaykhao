<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // บัตรของขวัญแบบระบุยอดเงิน — ผู้รับเลือกทริปและวันเองได้ ต่างจาก "ของขวัญ
        // ทริป" (bookings.is_gift) ที่ผู้ให้ต้องเลือกให้ตั้งแต่แรก
        if (! Schema::hasTable('gift_vouchers')) {
            Schema::create('gift_vouchers', function (Blueprint $table) {
                $table->id();
                // รหัสคือตัวเงิน — ใครถือรหัสก็ใช้ได้จนกว่าจะมีคนผูกเข้าบัญชี (owner)
                $table->string('code', 20)->unique();
                $table->foreignId('purchaser_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->decimal('balance', 10, 2)->default(0);
                // pending | under_review | active | rejected | cancelled
                $table->string('status', 20)->default('pending');
                $table->string('recipient_name', 100)->nullable();
                $table->string('from_name', 100)->nullable();
                $table->string('message', 300)->nullable();
                $table->string('design', 20)->default('forest');
                // ทีมงานออกให้โดยไม่มีเงินเข้า (ชดเชย/รางวัล) — ไม่นับเป็นยอดขายบัตร
                $table->boolean('is_complimentary')->default(false);
                // หลักฐานการโอน — เก็บบน private slip disk เหมือนสลิปการจอง
                $table->string('slip_path')->nullable();
                $table->string('slip_ocr_status', 20)->nullable();
                $table->json('slip_ocr_result')->nullable();
                $table->string('payment_ref', 40)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_note', 300)->nullable();
                $table->timestamps();

                $table->index('status', 'gift_vouchers_status_idx');
            });
        }

        // สมุดบัญชีของบัตรแต่ละใบ — ทุกบาทที่เข้า/ออกมีแถวของมัน ยอดคงเหลือตรวจย้อนได้
        if (! Schema::hasTable('gift_voucher_transactions')) {
            Schema::create('gift_voucher_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('gift_voucher_id')->constrained('gift_vouchers')->cascadeOnDelete();
                $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
                // purchase | redeem | restore | adjust
                $table->string('type', 20);
                // บวก = เข้าบัตร, ลบ = ออกจากบัตร
                $table->decimal('amount', 10, 2);
                $table->decimal('balance_after', 10, 2);
                $table->string('note', 300)->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['gift_voucher_id', 'type'], 'gvt_voucher_type_idx');
            });
        }

        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'gift_voucher_id')) {
                $table->foreignId('gift_voucher_id')->nullable()->after('discount_amount')
                    ->constrained('gift_vouchers')->nullOnDelete();
            }
            // ยอดที่จ่ายด้วยบัตรของขวัญ — หักออกจาก total_amount แล้ว (total_amount คือยอดที่
            // ยังต้องจ่ายเป็นเงิน) ยอดค่าทริปเต็มจึงเป็น total_amount + voucher_amount
            if (! Schema::hasColumn('bookings', 'voucher_amount')) {
                $table->decimal('voucher_amount', 10, 2)->default(0)->after('gift_voucher_id');
            }
            // ยอดที่คืนกลับเข้าบัตรแล้ว (ยกเลิก/คืนเงิน) — กันคืนซ้ำ
            if (! Schema::hasColumn('bookings', 'voucher_restored_amount')) {
                $table->decimal('voucher_restored_amount', 10, 2)->default(0)->after('voucher_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'gift_voucher_id')) {
                $table->dropConstrainedForeignId('gift_voucher_id');
            }
            foreach (['voucher_amount', 'voucher_restored_amount'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('gift_voucher_transactions');
        Schema::dropIfExists('gift_vouchers');
    }
};
