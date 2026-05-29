<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks when a customer used their one-time reschedule. A non-null value means
 * the booking has already been moved once and cannot be rescheduled again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('bookings', 'rescheduled_at')) {
                $table->timestamp('rescheduled_at')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'rescheduled_at')) {
                $table->dropColumn('rescheduled_at');
            }
        });
    }
};
