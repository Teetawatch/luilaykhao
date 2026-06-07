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
        // Idempotent: a previous run may have created/backfilled the pivot before
        // failing on the column drop (MySQL won't drop an index a foreign key needs).
        if (! Schema::hasTable('schedule_photo')) {
            Schema::create('schedule_photo', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->constrained('trip_schedules')->cascadeOnDelete();
                $table->foreignId('photo_id')->constrained('schedule_photos')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['schedule_id', 'photo_id']);
                $table->index(['schedule_id', 'sort_order']);
            });
        }

        // While the legacy column still exists: backfill the pivot, then drop it.
        if (Schema::hasColumn('schedule_photos', 'schedule_id')) {
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
                    // insertOrIgnore + the unique(schedule_id, photo_id) key make a
                    // re-run safe if the pivot was already partially backfilled.
                    DB::table('schedule_photo')->insertOrIgnore($insert);
                }
            });

            // The link now lives on the pivot; drop the columns from the photo record.
            // Order matters on MySQL: the foreign key relies on the composite index,
            // so the FK must be dropped before the index, and the index before the
            // columns. SQLite rebuilds the table and tolerates the same order.
            Schema::table('schedule_photos', function (Blueprint $t) {
                $t->dropForeign(['schedule_id']);
                $t->dropIndex(['schedule_id', 'sort_order']);
                $t->dropColumn(['schedule_id', 'sort_order']);
            });
        }
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
