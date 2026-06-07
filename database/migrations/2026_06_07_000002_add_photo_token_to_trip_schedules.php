<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A nullable, unguessable token that turns a round's photo set into a public album
 * download page (/album/{token}). Presence of the token = album is shared; null = off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->string('photo_token', 32)->nullable()->unique()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trip_schedules', function (Blueprint $table) {
            $table->dropUnique(['photo_token']);
            $table->dropColumn('photo_token');
        });
    }
};
