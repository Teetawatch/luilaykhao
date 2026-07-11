<?php

namespace App\Jobs;

use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mirrors a social provider's profile picture onto our own media storage.
 *
 * LINE hands out rotating CDN URLs (profile.line-scdn.net/0h…) that 404 the
 * moment the user changes their LINE photo — so a URL we hot-linked once goes
 * dead and the customer's avatar disappears. This job downloads the current
 * picture at login time and stores a stable copy on our public disk (R2/public),
 * pointing the user's avatar at that instead. Content-addressed by the source
 * URL so an unchanged picture is only ever fetched once.
 */
class MirrorSocialAvatarJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public int $userId,
        public string $sourceUrl,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $disk = Storage::disk(MediaDisk::name());
        $path = 'avatars/social/'.sha1($this->sourceUrl).'.jpg';

        // Already mirrored this exact URL — nothing changed since last login.
        if ($user->avatar === $path && $disk->exists($path)) {
            return;
        }

        try {
            $response = Http::timeout(15)->get($this->sourceUrl);
        } catch (\Throwable $e) {
            Log::warning('MirrorSocialAvatarJob: download failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($response->failed()) {
            // Source is gone (e.g. a rotated LINE URL). If the user is still
            // pointing at this dead URL, clear it so avatar_url falls back to the
            // generated placeholder instead of serving a broken image.
            if ($user->avatar === $this->sourceUrl) {
                $user->update(['avatar' => null]);
            }

            return;
        }

        $body = $response->body();

        // Guard against non-image responses (error pages, redirects to HTML).
        $contentType = $response->header('Content-Type');
        if ($body === '' || ($contentType && ! str_starts_with($contentType, 'image/'))) {
            return;
        }

        $disk->put($path, $body, 'public');
        $user->update(['avatar' => $path]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('MirrorSocialAvatarJob failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
