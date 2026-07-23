<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * สถานที่ (ภูเขา/เกาะ/น้ำตก/อุทยาน) แยกออกจากทริปที่ขาย — ข้อมูลของ "ที่นั้น"
 * ไม่หายไปเมื่อรอบเดินทางปิด และเป็นฐานของปฏิทิน "เดือนไหนไปไหนดี"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('mountain');
            $table->string('region')->nullable();
            $table->string('province')->nullable();
            $table->string('park')->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('elevation_m')->nullable();
            $table->decimal('trail_distance_km', 6, 2)->nullable();
            $table->unsignedInteger('elevation_gain_m')->nullable();
            $table->string('difficulty')->nullable();

            // เดือนที่ควรไป / เดือนที่ปิด — เก็บเป็น array ของเลข 1-12
            $table->json('best_months')->nullable();
            $table->json('closed_months')->nullable();
            $table->text('season_note')->nullable();
            $table->text('closure_note')->nullable();

            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->json('highlights')->nullable();
            $table->json('know_before')->nullable();

            $table->string('cover_image')->nullable();
            $table->json('gallery')->nullable();

            $table->string('status')->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('views_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'region']);
            $table->index('type');
        });

        // ทริปหนึ่งอาจแวะหลายที่ และที่หนึ่งอาจมีหลายทริป จึงเป็น many-to-many
        Schema::create('place_trip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['place_id', 'trip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_trip');
        Schema::dropIfExists('places');
    }
};
