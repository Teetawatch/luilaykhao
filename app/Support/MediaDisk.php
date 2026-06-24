<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Single source of truth for which filesystem disk user-uploaded media lives
 * on. Public media (reviews, chat, contact, SOS, media library, avatars,
 * trip/schedule photos) goes on the public disk/bucket; sensitive media
 * (payment slips) goes on a separate PRIVATE disk and is only ever exposed
 * through short-lived signed URLs. Routing every upload + URL through here keeps
 * a file's write location and its URL from ever drifting apart.
 */
class MediaDisk
{
    /** How long a signed slip URL stays valid. */
    public const SLIP_URL_TTL_MINUTES = 30;

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

    /**
     * Make a media URL portable across clients. A URL that points at our own app
     * host is returned host-relative ("/storage/…") so each client (web, mobile,
     * any environment) resolves it against its own base — a phone can't reach a
     * baked-in "http://localhost/…". External URLs (e.g. R2) are left untouched.
     */
    public static function hostRelative(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $appHost = rtrim((string) config('app.url'), '/');
        if ($appHost !== '' && str_starts_with($url, $appHost.'/')) {
            return substr($url, strlen($appHost)); // keep the leading "/…"
        }

        return $url;
    }

    /**
     * The PRIVATE disk for sensitive uploads (payment slips). Uses the dedicated
     * R2 private bucket when configured, otherwise the local 'local' disk —
     * which, unlike 'public', is not web-served.
     */
    public static function slipDisk(): string
    {
        return config('filesystems.disks.r2_private.bucket') ? 'r2_private' : 'local';
    }

    /**
     * A short-lived signed URL for a slip. R2 yields a native presigned URL;
     * disks without temporary-URL support (local dev) fall back to a signed
     * app route that streams the file. Never returns a directly-public URL.
     */
    public static function slipUrl(?string $path, int $ttlMinutes = self::SLIP_URL_TTL_MINUTES): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        // Slips always live under slips/. A path that doesn't (e.g. a "0" left
        // behind by a failed upload that stored a falsy return value) would only
        // ever mint a link to a non-existent object, so don't.
        if (! str_starts_with($path, 'slips/')) {
            return null;
        }

        $expiry = now()->addMinutes($ttlMinutes);

        try {
            return Storage::disk(self::slipDisk())->temporaryUrl($path, $expiry);
        } catch (\Throwable $e) {
            return URL::temporarySignedRoute(
                'slips.show',
                $expiry,
                ['token' => Crypt::encryptString($path)],
            );
        }
    }
}
