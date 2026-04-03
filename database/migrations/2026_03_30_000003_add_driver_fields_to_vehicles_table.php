<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('license_plate')->nullable()->after('capacity');
            $table->string('color')->nullable()->after('license_plate');
            $table->string('driver_name')->nullable()->after('color');
            $table->string('driver_phone')->nullable()->after('driver_name');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['license_plate', 'color', 'driver_name', 'driver_phone']);
        });
    }
};
