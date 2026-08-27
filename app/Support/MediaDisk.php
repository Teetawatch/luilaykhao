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
     * The PRIVATE disk for sensitive uploads (payment slips). Uses the dedicated
     * R2 private bucket when configured, otherwise the local 'local' disk —
     * which, unlike 'public', is not web-served.
     */
    public static function slipDisk(): string
    {
        return config('filesystems.disks.r2_private.bucket') ? 'r2_private' : 'local';
    }

    /**
     * The only folders on the private disk a link may ever be minted for.
     * Everything private is uploaded into one of these, so anything else is a
     * bad path (e.g. a "0" left behind by a failed upload that stored a falsy
     * return value) or an attempt to reach somewhere it shouldn't.
     *
     * driver-documents/ holds scans of a driver's licence — an identity document,
     * so it never goes near the public bucket the rest of the media library uses.
     */
    public const PRIVATE_PREFIXES = ['slips/', 'booking-documents/', 'driver-documents/'];

    /**
     * A short-lived signed URL for a slip. R2 yields a native presigned URL;
     * disks without temporary-URL support (local dev) fall back to a signed
     * app route that streams the file. Never returns a directly-public URL.
     */
    public static function slipUrl(?string $path, int $ttlMinutes = self::SLIP_URL_TTL_MINUTES): ?string
    {
        if ($path !== null && $path !== '' && ! str_starts_with($path, 'slips/')) {
            return null;
        }

        return self::privateUrl($path, $ttlMinutes);
    }

    /**
     * A short-lived signed URL for any file on the private disk — slips and the
     * identity documents customers attach to a booking. Same mechanism as
     * [self::slipUrl]; the folder allow-list is the guard.
     */
    public static function privateUrl(?string $path, int $ttlMinutes = self::SLIP_URL_TTL_MINUTES): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (! self::isPrivatePath($path)) {
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

    /** ไฟล์นี้อยู่ในโฟลเดอร์ส่วนตัวที่อนุญาตไหม */
    public static function isPrivatePath(string $path): bool
    {
        foreach (self::PRIVATE_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
