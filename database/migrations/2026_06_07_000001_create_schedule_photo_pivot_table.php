<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move schedule photos from a one-to-one (schedule_photos.schedule_id) link to a
 * many-to-many pivot so a single uploaded photo (one R2 object) can be shared across
 * several rounds of the same trip without re-uploading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_photo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
            $table->foreignId('photo_id')->constrained('schedule_photos')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['schedule_id', 'photo_id']);
            $table->index(['schedule_id', 'sort_order']);
        });

        // Backfill the pivot from the existing one-to-one rows.
        DB::table('schedule_photos')->orderBy('id')->chunk(200, function ($rows) {
            $now = now();
            $insert = $rows->map(fn ($row) => [
                'schedule_id' => $row->schedule_id,
                'photo_id' => $row->id,
                'sort_order' => $row->sort_order ?? 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            if ($insert) {
                DB::table('schedule_photo')->insert($insert);
            }
        });

        // The link now lives on the pivot; drop the columns from the photo record.
        // The composite index must go first or SQLite refuses to drop schedule_id.
        Schema::table('schedule_photos', function (Blueprint $table) {
            $table->dropIndex(['schedule_id', 'sort_order']);
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropColumn('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_photos', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->constrained('trip_schedules')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
        });

        // Restore the one-to-one link from the first schedule each photo is attached to.
        DB::table('schedule_photo')->orderBy('photo_id')->orderBy('schedule_id')->chunk(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('schedule_photos')
                    ->where('id', $row->photo_id)
                    ->whereNull('schedule_id')
                    ->update([
                        'schedule_id' => $row->schedule_id,
                        'sort_order' => $row->sort_order,
                    ]);
            }
        });

        Schema::dropIfExists('schedule_photo');
    }
};
