<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->boolean('alert_price_drop')->default(true);
            $table->boolean('alert_new_schedule')->default(true);
            $table->boolean('alert_low_seats')->default(true);
            $table->decimal('last_notified_price', 10, 2)->nullable();
            $table->unsignedTinyInteger('low_seat_threshold')->default(5);
            $table->timestamps();

            $table->unique(['user_id', 'trip_id']);
            $table->index('trip_id');
        });

        Schema::create('trip_alert_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('trip_schedules')->cascadeOnDelete();
            $table->string('type', 32);
            $table->timestamps();

            $table->unique(['trip_alert_id', 'schedule_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_alert_dispatches');
        Schema::dropIfExists('trip_alerts');
    }
};
