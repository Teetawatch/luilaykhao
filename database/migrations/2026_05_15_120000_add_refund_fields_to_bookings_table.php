<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds refund tracking columns so the mobile app's Refund Status screen can
 * surface where each cancellation sits in the reimbursement pipeline.
 *
 * Conventions:
 *   refund_status  – 'pending' | 'processing' | 'completed' | 'rejected'
 *   refund_amount  – baht amount actually returned (often less than paid_amount
 *                    because of the cancellation policy fee schedule).
 *   refunded_at    – timestamp when the funds left the merchant account.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'refund_status')) {
                $table->string('refund_status', 32)->nullable()->after('cancelled_at');
            }
            if (!Schema::hasColumn('bookings', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable()->after('refund_status');
            }
            if (!Schema::hasColumn('bookings', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('refund_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            foreach (['refund_status', 'refund_amount', 'refunded_at'] as $column) {
                if (Schema::hasColumn('bookings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
