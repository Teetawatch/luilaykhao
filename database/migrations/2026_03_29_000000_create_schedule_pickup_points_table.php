<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_pickup_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->string('region'); // เช่น north, northeast, central, east, west, south
            $table->string('region_label'); // ชื่อภาษาไทย เช่น ภาคเหนือ
            $table->string('pickup_location'); // ชื่อจุดขึ้นรถ
            $table->decimal('price', 10, 2); // ราคาของภูมิภาคนี้
            $table->string('map_url')->nullable(); // Google Maps link
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable(); // หมายเหตุเพิ่มเติม เช่น เวลานัดพบ
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['schedule_id', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_pickup_points');
    }
};
