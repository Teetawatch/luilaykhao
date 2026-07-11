<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('inspected_by')->constrained('users')->cascadeOnDelete();
            // Snapshot of each checklist item: [{key, label, critical, ok}, ...]
            $table->json('items');
            // Every item ok.
            $table->boolean('passed')->default(false);
            // Any *critical* item failed — the driver departed with a known risk.
            $table->boolean('critical_failed')->default(false);
            $table->text('note')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();

            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
    }
};
