<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // trip_schedules: admin-configurable deposit amount or percent
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->boolean('deposit_enabled')->default(false)->after('installment_interval_days');
            $table->enum('deposit_type', ['amount', 'percent'])->nullable()->after('deposit_enabled');
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('deposit_type');
            $table->unsignedTinyInteger('deposit_percent')->nullable()->after('deposit_amount');
        });

        // bookings: track deposit / balance state. payment_type enum needs 'deposit'.
        // Use raw SQL because Laravel cannot ALTER an existing ENUM column portably.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE bookings MODIFY COLUMN payment_type ENUM('full','installment','deposit') NOT NULL DEFAULT 'full'"
            );
        }

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('deposit_amount', 10, 2)->nullable()->after('installment_interval_days');
            $table->decimal('balance_amount', 10, 2)->nullable()->after('deposit_amount');
            $table->timestamp('balance_due_at')->nullable()->after('balance_amount');
            $table->timestamp('balance_paid_at')->nullable()->after('balance_due_at');
            $table->string('balance_payment_ref')->nullable()->after('balance_paid_at');
            $table->string('balance_slip_path')->nullable()->after('balance_payment_ref');
            $table->timestamp('balance_transfer_datetime')->nullable()->after('balance_slip_path');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['deposit_enabled', 'deposit_type', 'deposit_amount', 'deposit_percent']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_amount', 'balance_amount', 'balance_due_at', 'balance_paid_at',
                'balance_payment_ref', 'balance_slip_path', 'balance_transfer_datetime',
            ]);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE bookings MODIFY COLUMN payment_type ENUM('full','installment') NOT NULL DEFAULT 'full'"
            );
        }
    }
};
