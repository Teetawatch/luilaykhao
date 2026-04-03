<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['routine', 'repair', 'inspection', 'insurance', 'registration']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('scheduled_date');
            $table->date('completed_date')->nullable();
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'overdue'])->default('scheduled');
            $table->decimal('cost', 10, 2)->default(0);
            $table->string('performed_by')->nullable();
            $table->text('notes')->nullable();
            $table->integer('next_km')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenances');
    }
};
