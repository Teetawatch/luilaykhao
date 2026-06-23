<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trip detail videos — a list of R2 video URLs shown after the photo gallery
 * on the trip detail page and in the customer app. Mirrors the existing
 * `gallery` array of image URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->json('videos')->nullable()->after('gallery');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('videos');
        });
    }
};
