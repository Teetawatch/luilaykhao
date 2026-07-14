<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Equipment the customer can rent when booking this trip. JSON array of
     * { name, price, image_url?, description? } — indexed the same way add-ons
     * are, but rented per-unit with a quantity chosen at booking time.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->json('rental_items')->nullable()->after('faqs');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('rental_items');
        });
    }
};
