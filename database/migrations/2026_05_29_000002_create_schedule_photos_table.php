<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->string('disk', 32)->default('r2');
            $table->string('path');
            $table->string('url')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 64)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['schedule_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_photos');
    }
};
