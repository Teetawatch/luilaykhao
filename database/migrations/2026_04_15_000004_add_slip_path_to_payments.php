<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // bookings: slip_path + transfer_datetime (add only if missing)
        if (!Schema::hasColumn('bookings', 'slip_path')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->string('slip_path')->nullable()->after('paid_at');
            });
        }
        if (!Schema::hasColumn('bookings', 'transfer_datetime')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('transfer_datetime')->nullable()->after('slip_path');
            });
        }

        // installment_payments: only transfer_datetime (slip_path already exists)
        if (!Schema::hasColumn('installment_payments', 'transfer_datetime')) {
            Schema::table('installment_payments', function (Blueprint $table) {
                $table->timestamp('transfer_datetime')->nullable()->after('slip_path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['slip_path', 'transfer_datetime']);
        });

        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropColumn('transfer_datetime');
        });
    }
};
