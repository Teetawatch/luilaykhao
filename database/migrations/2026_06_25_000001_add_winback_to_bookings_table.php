<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // True when the booking was auto-cancelled by ExpirePendingBookingsJob
            // for non-payment — i.e. an abandoned booking eligible for win-back.
            $table->boolean('was_auto_expired')->default(false)->after('cancelled_at');
            // When a win-back nudge was sent, so each abandoned booking is
            // followed up at most once.
            $table->timestamp('winback_sent_at')->nullable()->after('was_auto_expired');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['was_auto_expired', 'winback_sent_at']);
        });
    }
};
