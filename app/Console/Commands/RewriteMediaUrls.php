<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repoints media URLs that were saved as absolute {APP_URL}/storage/… strings
 * (media library uploads embedded in trip/article/hero/category content, review
 * photos, gallery arrays, photo-album URLs…) to their Cloudflare R2 equivalent,
 * so the browser loads them straight from R2/CDN instead of round-tripping the
 * origin. A plain base-URL string REPLACE — works uniformly on string columns,
 * JSON arrays, and rich HTML bodies. Idempotent: re-running finds nothing left.
 *
 * Run AFTER the files are on R2 (`media:migrate-to-r2`) and BEFORE pruning.
 */
class RewriteMediaUrls extends Command
{
    protected $signature = 'media:rewrite-urls
        {--dry-run : Report how many rows would change without writing}
        {--from= : Override the old base URL (default: APP_URL/storage/)}';

    protected $description = 'Rewrite absolute /storage media URLs stored in the database to their R2 URL';

    /** table => columns that may hold an absolute media URL. */
    private const TARGETS = [
        'trips' => ['cover_image', 'thumbnail_image', 'gallery', 'videos'],
        'articles' => ['body', 'cover_image_url'],
        'reviews' => ['images', 'videos'],
        'contacts' => ['images'],
        'hero_slides' => ['image_url'],
        'categories' => ['image_url'],
        'schedule_photos' => ['url', 'thumb_url'],
        'trip_photos' => ['url', 'thumb_url'],
        'users' => ['avatar'],
    ];

    public function handle(): int
    {
        $to = rtrim((string) config('filesystems.disks.r2.url'), '/').'/';
        if ($to === '/') {
            $this->error('R2 public URL is not configured (filesystems.disks.r2.url is empty). Set R2_PUBLIC_URL first.');

            return self::FAILURE;
        }

        $from = $this->option('from')
            ? rtrim((string) $this->option('from'), '/').'/'
            : rtrim((string) config('app.url'), '/').'/storage/';

        if ($from === $to) {
            $this->error("Old and new base URLs are identical ({$from}); nothing to do.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line("Rewriting media URLs:  {$from}  ->  {$to}");
        if ($dryRun) {
            $this->warn('DRY RUN — no rows will be changed.');
        }

        $totalRows = 0;

        foreach (self::TARGETS as $table => $columns) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                    continue;
                }

                // JSON-cast columns (gallery, images, videos) are stored with
                // forward slashes escaped as \/ by json_encode, so match and
                // replace that variant too. Running both passes is safe: a plain
                // string column never contains the escaped form and vice-versa.
                $fromEsc = str_replace('/', '\/', $from);
                $toEsc = str_replace('/', '\/', $to);

                $matches = DB::table($table)
                    ->where($column, 'like', '%'.$from.'%')
                    ->orWhere($column, 'like', '%'.$fromEsc.'%')
                    ->count();

                if ($matches === 0) {
                    continue;
                }

                $totalRows += $matches;
                $this->line("  {$table}.{$column}: {$matches} row(s)".($dryRun ? ' would be' : '').' rewritten.');

                if (! $dryRun) {
                    // REPLACE swaps every occurrence in the column, so multiple
                    // embedded URLs in one HTML body are handled in a single
                    // pass; the nested call covers the escaped JSON variant.
                    DB::update(
                        "update `{$table}` set `{$column}` = replace(replace(`{$column}`, ?, ?), ?, ?) where `{$column}` like ? or `{$column}` like ?",
                        [$from, $to, $fromEsc, $toEsc, '%'.$from.'%', '%'.$fromEsc.'%'],
                    );
                }
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run complete — {$totalRows} row(s) would be rewritten."
            : "Done — {$totalRows} row(s) rewritten to R2.");

        return self::SUCCESS;
    }
}
