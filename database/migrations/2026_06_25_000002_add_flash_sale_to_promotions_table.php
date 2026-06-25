<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Marks a promotion as a time-boxed flash sale so the app can render
            // a live countdown + "seats/uses left" urgency instead of a plain code.
            $table->boolean('is_flash_sale')->default(false)->after('is_active');
            // Precise deadline for the countdown (end_date is only date-granular).
            $table->timestamp('ends_at')->nullable()->after('end_date');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['is_flash_sale', 'ends_at']);
        });
    }
};
