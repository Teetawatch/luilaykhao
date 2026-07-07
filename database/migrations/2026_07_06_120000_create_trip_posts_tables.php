<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ฟีดรูปหลังทริป — โพสต์ของลูกค้าที่เคยเดินทางจริง (UGC / social proof)
        Schema::create('trip_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('trip_schedules')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('caption')->nullable();
            $table->json('photos'); // list<{path, url, width?, height?}>
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('reports_count')->default(0);
            $table->string('status', 20)->default('published'); // published | hidden
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trip_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('trip_post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('trip_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
        });

        Schema::create('trip_post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('trip_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });

        Schema::create('trip_post_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('trip_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_post_reports');
        Schema::dropIfExists('trip_post_comments');
        Schema::dropIfExists('trip_post_likes');
        Schema::dropIfExists('trip_posts');
    }
};
