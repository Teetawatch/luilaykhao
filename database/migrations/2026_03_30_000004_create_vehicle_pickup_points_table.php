<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_pickup_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('region'); // north, northeast, central, east, west, south
            $table->string('region_label'); // ภาคเหนือ, ภาคอีสาน, ฯลฯ
            $table->string('pickup_location'); // ชื่อจุดขึ้นรถ
            $table->string('map_url')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable(); // เวลานัดพบ / หมายเหตุ
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_pickup_points');
    }
};
