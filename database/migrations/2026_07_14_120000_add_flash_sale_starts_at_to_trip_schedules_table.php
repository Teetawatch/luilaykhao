<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            // Optional scheduled start for a flash sale. When set to a future time
            // the sale is configured but stays dormant (normal price, no push)
            // until it passes; null means it starts the moment it's enabled.
            $table->timestamp('flash_sale_starts_at')->nullable()->after('flash_sale_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn('flash_sale_starts_at');
        });
    }
};
