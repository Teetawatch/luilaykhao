<?php

namespace App\Console\Commands;

use App\Jobs\MirrorSocialAvatarJob;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Backfills self-hosted avatar copies for social users who signed in before the
 * mirroring behaviour landed (commit 3f82f66). Their avatar column still holds
 * the provider's hot-linked URL — for LINE those are rotating CDN links that
 * 404 the moment the customer changes their photo, so the avatar renders broken.
 *
 * For each such user we dispatch MirrorSocialAvatarJob, which either downloads
 * the still-live picture onto our media disk, or (when the source URL is already
 * dead) clears the column so avatar_url falls back to the generated placeholder
 * instead of a broken image. Runs safely more than once — users already on a
 * disk-relative path are skipped by the LIKE filter.
 */
class MirrorSocialAvatars extends Command
{
    protected $signature = 'avatars:mirror-social {--provider= : Limit to one provider (e.g. line, google, facebook)} {--sync : Run inline instead of queueing}';

    protected $description = 'Mirror social users\' hot-linked provider avatars onto our own storage (fixes dead LINE photos).';

    public function handle(): int
    {
        $query = User::query()
            ->whereNotNull('social_provider')
            ->where('avatar', 'like', 'http%');

        if ($provider = $this->option('provider')) {
            $query->where('social_provider', $provider);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No social users with a hot-linked avatar to mirror.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info("Mirroring {$total} avatar(s)".($sync ? ' inline…' : ' via the queue…'));

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->select('id', 'avatar')->chunkById(200, function ($users) use ($sync, $bar) {
            foreach ($users as $user) {
                $job = new MirrorSocialAvatarJob($user->id, $user->avatar);
                $sync ? dispatch_sync($job) : dispatch($job);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info($sync
            ? 'Done. Avatars re-mirrored (dead source URLs were cleared to the placeholder).'
            : "Dispatched {$total} MirrorSocialAvatarJob(s). Ensure a queue worker is running to process them.");

        return self::SUCCESS;
    }
}
