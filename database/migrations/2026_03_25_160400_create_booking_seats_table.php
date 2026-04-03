<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->string('seat_id', 10);
            $table->string('passenger_name')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['schedule_id', 'seat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_seats');
    }
};
