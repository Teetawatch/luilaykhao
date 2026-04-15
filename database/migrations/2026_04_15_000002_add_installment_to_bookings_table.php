<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('payment_type', ['full', 'installment'])->default('full')->after('payment_method');
            $table->unsignedTinyInteger('installment_count')->nullable()->after('payment_type');
            $table->unsignedSmallInteger('installment_interval_days')->nullable()->after('installment_count');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['payment_type', 'installment_count', 'installment_interval_days']);
        });
    }
};
