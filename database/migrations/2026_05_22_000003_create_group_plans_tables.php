<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('invite_code', 16)->unique();
            $table->string('name')->nullable();
            $table->enum('status', ['open', 'booked', 'cancelled', 'expired'])->default('open');
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['host_user_id', 'status']);
        });

        Schema::create('group_plan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_plan_id')->constrained('group_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('seat_id')->nullable();
            $table->string('passenger_title')->nullable();
            $table->string('passenger_name')->nullable();
            $table->string('passenger_phone')->nullable();
            $table->string('passenger_email')->nullable();
            $table->text('allergies')->nullable();
            $table->text('health_notes')->nullable();
            $table->boolean('is_host')->default(false);
            $table->enum('status', ['joined', 'ready', 'left'])->default('joined');
            $table->timestamps();

            $table->unique(['group_plan_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_plan_members');
        Schema::dropIfExists('group_plans');
    }
};
