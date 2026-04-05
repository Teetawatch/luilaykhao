<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->dropUnique(['schedule_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->unique(['schedule_id', 'region']);
        });
    }
};
