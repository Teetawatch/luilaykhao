<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for which filesystem disk user-uploaded media lives
 * on. Prefers Cloudflare R2 when it's configured, otherwise the local public
 * disk. Every upload (reviews, chat, contact, SOS, slips, media library,
 * avatars, trip/schedule photos) must both store files and build their public
 * URLs through here, so a file's write location and its URL never drift apart.
 */
class MediaDisk
{
    /**
     * The disk to read/write media on. R2 when it's wired up, else 'public'.
     */
    public static function name(): string
    {
        if (config('filesystems.default') === 'r2') {
            return 'r2';
        }

        return config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
    }

    /**
     * Public URL for a stored path. Already-absolute URLs (e.g. legacy records
     * that saved a full URL) are returned untouched.
     */
    public static function url(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk(self::name())->url($path);
    }
}
