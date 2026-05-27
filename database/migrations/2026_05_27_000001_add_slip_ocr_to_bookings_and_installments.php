<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('slip_ocr_status', 30)->nullable()->after('slip_path');
            $table->json('slip_ocr_result')->nullable()->after('slip_ocr_status');
            $table->string('balance_slip_ocr_status', 30)->nullable()->after('balance_slip_path');
            $table->json('balance_slip_ocr_result')->nullable()->after('balance_slip_ocr_status');
        });

        Schema::table('installment_payments', function (Blueprint $table) {
            $table->string('slip_ocr_status', 30)->nullable()->after('slip_path');
            $table->json('slip_ocr_result')->nullable()->after('slip_ocr_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['slip_ocr_status', 'slip_ocr_result', 'balance_slip_ocr_status', 'balance_slip_ocr_result']);
        });

        Schema::table('installment_payments', function (Blueprint $table) {
            $table->dropColumn(['slip_ocr_status', 'slip_ocr_result']);
        });
    }
};
