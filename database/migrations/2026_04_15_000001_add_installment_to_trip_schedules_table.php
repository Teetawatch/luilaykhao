<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->boolean('installment_enabled')->default(false)->after('price_override');
            $table->unsignedTinyInteger('installment_count')->default(2)->after('installment_enabled');
            $table->unsignedSmallInteger('installment_interval_days')->default(30)->after('installment_count');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['installment_enabled', 'installment_count', 'installment_interval_days']);
        });
    }
};
