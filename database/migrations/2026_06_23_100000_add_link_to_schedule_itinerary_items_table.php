<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_itinerary_items', function (Blueprint $table) {
            // ลิงก์แนบของรายการ เช่น Google Maps จุดนัดพบ — null ได้
            $table->string('link', 2048)->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_itinerary_items', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
