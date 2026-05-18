<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sos_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('message', 255)->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sos_alerts');
    }
};
