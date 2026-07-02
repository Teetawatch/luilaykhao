<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            // Per-round flash sale: admin sets a discounted selling price and an
            // end time; the discount applies while enabled and not yet lapsed.
            $table->boolean('flash_sale_enabled')->default(false)->after('price_override');
            $table->decimal('flash_sale_price', 10, 2)->nullable()->after('flash_sale_enabled');
            $table->timestamp('flash_sale_ends_at')->nullable()->after('flash_sale_price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropColumn(['flash_sale_enabled', 'flash_sale_price', 'flash_sale_ends_at']);
        });
    }
};
