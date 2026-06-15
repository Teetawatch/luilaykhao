<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            // When staff finishes picking up everyone at this point on a run.
            $table->timestamp('completed_at')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_pickup_points', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
