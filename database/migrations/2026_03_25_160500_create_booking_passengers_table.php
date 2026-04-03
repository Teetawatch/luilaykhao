<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->text('health_notes')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone', 20)->nullable();
            $table->string('dive_cert_level')->nullable();
            $table->string('cert_number')->nullable();
            $table->decimal('weight', 5, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_passengers');
    }
};
