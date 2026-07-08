<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds are paid by manual bank transfer, so admins need somewhere to attach
 * the transfer slip as proof. Stored on the private slip disk (same as payment
 * slips) and surfaced to the customer on the Refund Status screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'refund_slip_path')) {
                $table->string('refund_slip_path')->nullable()->after('refunded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'refund_slip_path')) {
                $table->dropColumn('refund_slip_path');
            }
        });
    }
};
