<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * หารค่าใช้จ่ายกับเพื่อนร่วมทริป — ดู SharedExpenseService
 *
 * อิงผู้โดยสาร (booking_passengers) ไม่ใช่บัญชี เพื่อนที่ไม่มีแอปก็ร่วมหารได้
 * โดยคนที่จองให้เป็นคนดูแลยอดแทน (นิยามเดียวกับ TripRosterService)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trip_shared_expenses')) {
            Schema::create('trip_shared_expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
                $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
                // คนที่ออกเงินไปก่อน
                $table->foreignId('payer_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
                $table->string('title', 60);
                $table->string('category', 20)->default('other');
                $table->decimal('amount', 10, 2);
                // equal = หารเท่ากัน · custom = ใส่ยอดของแต่ละคนเอง
                $table->string('split_mode', 10)->default('equal');
                $table->string('note', 200)->nullable();
                $table->timestamps();

                $table->index(['schedule_id', 'created_at'], 'trip_shared_expenses_schedule_created_idx');
            });
        }

        if (! Schema::hasTable('trip_shared_expense_shares')) {
            Schema::create('trip_shared_expense_shares', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained('trip_shared_expenses')->cascadeOnDelete();
                $table->foreignId('passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                $table->timestamps();

                $table->unique(['expense_id', 'passenger_id'], 'trip_shared_shares_expense_passenger_uq');
            });
        }

        if (! Schema::hasTable('trip_shared_settlements')) {
            Schema::create('trip_shared_settlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
                // ลูกหนี้ → เจ้าหนี้
                $table->foreignId('from_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
                $table->foreignId('to_passenger_id')->constrained('booking_passengers')->cascadeOnDelete();
                $table->decimal('amount', 10, 2);
                // transfer = โอน · cash = เงินสด
                $table->string('method', 10)->default('transfer');
                $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['schedule_id', 'created_at'], 'trip_shared_settlements_schedule_created_idx');
            });
        }

        // พร้อมเพย์สำหรับรับเงินคืนจากเพื่อน — ตั้งครั้งเดียวใช้ทุกทริป (เข้ารหัส)
        if (! Schema::hasColumn('users', 'receive_promptpay_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('receive_promptpay_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'receive_promptpay_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('receive_promptpay_id');
            });
        }
        Schema::dropIfExists('trip_shared_settlements');
        Schema::dropIfExists('trip_shared_expense_shares');
        Schema::dropIfExists('trip_shared_expenses');
    }
};
