<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Review;
use App\Models\SchedulePhoto;
use App\Models\TripPhoto;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One-off migration that lifts every locally-stored upload onto Cloudflare R2
 * and rewrites the few DB columns that hold absolute URLs / a disk name. Safe to
 * re-run: files already on R2 are skipped, and URL rewrites are idempotent.
 */
class MigrateMediaToR2 extends Command
{
    protected $signature = 'media:migrate-to-r2 {--dry-run : Report what would change without writing anything} {--force : Overwrite files that already exist on R2}';

    protected $description = 'Copy existing local uploads to R2 and repoint database references';

    /** Top-level media folders that live on the local public disk. */
    private const SEGMENTS = ['reviews', 'contacts', 'chat', 'sos', 'slips', 'media', 'avatars', 'pickup-points', 'trips', 'schedules'];

    public function handle(): int
    {
        if (! config('filesystems.disks.r2.bucket')) {
            $this->error('R2 is not configured (filesystems.disks.r2.bucket is empty). Set the R2_* env vars first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun) {
            $this->warn('DRY RUN — no files or database rows will be changed.');
        }

        $public = Storage::disk('public');
        $r2 = Storage::disk('r2');

        $this->copyDiskFiles($public, $r2, $dryRun, $force);
        $this->copyLegacyWebRootAvatars($r2, $dryRun, $force);
        $this->rewriteJsonUrlColumn(Review::query(), 'images', $r2, $dryRun);
        $this->rewriteJsonUrlColumn(Contact::query(), 'images', $r2, $dryRun);
        $this->repointPhotoDiskColumn(TripPhoto::query(), $dryRun);
        $this->repointPhotoDiskColumn(SchedulePhoto::query(), $dryRun);
        $this->repointLegacyAvatarPaths($dryRun);

        $this->newLine();
        $this->info($dryRun ? 'Dry run complete.' : 'Migration complete.');

        return self::SUCCESS;
    }

    /**
     * Stream every file on the local public disk up to R2, preserving its path.
     */
    private function copyDiskFiles(Filesystem $public, Filesystem $r2, bool $dryRun, bool $force): void
    {
        $files = $public->allFiles();
        $copied = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $path) {
            if (! $force && $r2->exists($path)) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if (! $dryRun) {
                $stream = $public->readStream($path);
                $r2->writeStream($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $copied++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->line("Public disk → R2: {$copied} copied, {$skipped} already present.");
    }

    /**
     * Legacy avatars were written straight into public/avatars (the web root),
     * not the storage disk. Lift those into the R2 'avatars/' folder.
     */
    private function copyLegacyWebRootAvatars(Filesystem $r2, bool $dryRun, bool $force): void
    {
        $dir = public_path('avatars');
        if (! is_dir($dir)) {
            return;
        }

        $copied = 0;
        foreach (glob($dir.'/*') as $file) {
            if (! is_file($file)) {
                continue;
            }
            $target = 'avatars/'.basename($file);
            if (! $force && $r2->exists($target)) {
                continue;
            }
            if (! $dryRun) {
                $stream = fopen($file, 'r');
                $r2->writeStream($target, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $copied++;
        }

        $this->line("Legacy web-root avatars → R2: {$copied} copied.");
    }

    /**
     * Rewrite a JSON array column of absolute URLs (reviews.images,
     * contacts.images) so each entry points at its new R2 URL.
     */
    private function rewriteJsonUrlColumn($query, string $column, Filesystem $r2, bool $dryRun): void
    {
        $base = rtrim((string) config('filesystems.disks.r2.url'), '/');
        $updated = 0;

        $query->clone()->whereNotNull($column)->chunkById(200, function ($rows) use ($column, $r2, $base, $dryRun, &$updated) {
            foreach ($rows as $row) {
                $urls = $row->{$column};
                if (! is_array($urls) || $urls === []) {
                    continue;
                }

                $changed = false;
                $next = [];
                foreach ($urls as $url) {
                    $url = (string) $url;
                    if ($base && str_starts_with($url, $base)) {
                        $next[] = $url; // already on R2

                        continue;
                    }
                    $rel = $this->relativePath($url);
                    if ($rel === null) {
                        $next[] = $url; // unknown shape — leave it

                        continue;
                    }
                    $next[] = $r2->url($rel);
                    $changed = true;
                }

                if ($changed) {
                    $updated++;
                    if (! $dryRun) {
                        $row->update([$column => $next]);
                    }
                }
            }
        });

        $label = class_basename($query->getModel());
        $this->line("{$label}.{$column}: {$updated} row(s) repointed to R2.");
    }

    /**
     * Photos carry their own disk name; flip rows still pinned to 'public'.
     */
    private function repointPhotoDiskColumn($query, bool $dryRun): void
    {
        $pending = $query->clone()->where('disk', 'public');
        $count = $pending->count();
        if ($count > 0 && ! $dryRun) {
            $pending->update(['disk' => 'r2']);
        }

        $label = class_basename($query->getModel());
        $this->line("{$label}.disk: {$count} row(s) moved public → r2.");
    }

    /**
     * Legacy avatars were stored as '/avatars/…' (served from the web root).
     * Drop the leading slash so the accessor resolves them via the R2 disk now
     * that the files have been copied across.
     */
    private function repointLegacyAvatarPaths(bool $dryRun): void
    {
        $pending = User::query()->where('avatar', 'like', '/avatars/%');
        $count = $pending->count();

        if ($count > 0 && ! $dryRun) {
            $pending->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $user->update(['avatar' => ltrim($user->avatar, '/')]);
                }
            });
        }

        $this->line("User.avatar: {$count} legacy path(s) repointed to R2.");
    }

    /**
     * Recover a disk-relative path from an absolute URL by locating a known
     * media folder segment. Works regardless of the URL's host/prefix.
     */
    private function relativePath(string $url): ?string
    {
        foreach (self::SEGMENTS as $segment) {
            $pos = strpos($url, $segment.'/');
            if ($pos !== false) {
                return substr($url, $pos);
            }
        }

        return null;
    }
}
