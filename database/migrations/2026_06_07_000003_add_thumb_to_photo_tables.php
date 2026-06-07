<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store a downscaled thumbnail alongside each photo. The full-resolution file
 * (path/url) is never altered, so downloads stay sharp; grids load the thumbnail.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['schedule_photos', 'trip_photos'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('thumb_path')->nullable()->after('path');
                $t->string('thumb_url')->nullable()->after('url');
            });
        }
    }

    public function down(): void
    {
        foreach (['schedule_photos', 'trip_photos'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['thumb_path', 'thumb_url']);
            });
        }
    }
};
