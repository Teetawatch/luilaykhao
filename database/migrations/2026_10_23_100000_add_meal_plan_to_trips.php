<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            // มื้ออาหารรายวัน (เช้า/กลางวัน/เย็น × รวมในราคา/จ่ายเอง/ไม่มี) — รูปร่างอยู่ที่
            // App\Support\TripMealPlan ที่เดียว null = แอดมินยังไม่ได้ระบุ ไม่ใช่ "ไม่มีอาหาร"
            $table->json('meal_plan')->nullable()->after('checkin_bring');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('meal_plan');
        });
    }
};
