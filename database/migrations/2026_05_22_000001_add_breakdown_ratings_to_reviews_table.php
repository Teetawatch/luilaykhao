<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating_guide')->nullable()->after('rating');
            $table->unsignedTinyInteger('rating_vehicle')->nullable()->after('rating_guide');
            $table->unsignedTinyInteger('rating_food')->nullable()->after('rating_vehicle');
            $table->unsignedTinyInteger('rating_value')->nullable()->after('rating_food');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['rating_guide', 'rating_vehicle', 'rating_food', 'rating_value']);
        });
    }
};
