<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->date('departure_date');
            $table->date('return_date');
            $table->unsignedInteger('total_seats');
            $table->unsignedInteger('booked_seats')->default(0);
            $table->enum('transport_type', ['van', 'boat', 'bus']);
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->enum('status', ['open', 'closed', 'full', 'cancelled'])->default('open');
            $table->decimal('price_override', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_schedules');
    }
};
