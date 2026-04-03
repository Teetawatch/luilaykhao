<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->enum('type', ['trekking', 'diving', 'snorkeling', 'climbing']);
            $table->string('location');
            $table->text('description')->nullable();
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedInteger('max_participants');
            $table->decimal('price_per_person', 10, 2);
            $table->string('departure_point')->nullable();
            $table->enum('status', ['active', 'inactive', 'full'])->default('active');
            $table->string('cover_image')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
