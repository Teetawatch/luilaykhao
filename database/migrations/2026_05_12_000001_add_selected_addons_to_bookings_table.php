<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('selected_addons')->nullable()->after('total_amount');
            $table->decimal('addons_total', 10, 2)->default(0)->after('selected_addons');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['selected_addons', 'addons_total']);
        });
    }
};
