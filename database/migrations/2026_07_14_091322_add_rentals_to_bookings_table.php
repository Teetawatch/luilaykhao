<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of equipment rented on this booking and its total, mirroring the
     * add-on columns. `selected_rentals` is a frozen copy of each rented item
     * ({ name, unit_price, quantity, total_price, image_url }).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('selected_rentals')->nullable()->after('addons_total');
            $table->decimal('rentals_total', 10, 2)->default(0)->after('selected_rentals');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['selected_rentals', 'rentals_total']);
        });
    }
};
