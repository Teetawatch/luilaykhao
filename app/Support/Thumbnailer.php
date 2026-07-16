<?php

namespace App\Support;

use App\Jobs\GeneratePhotoThumbnailJob;

/**
 * Builds downscaled JPEG thumbnails with GD. Decoding a full-resolution photo is
 * slow and memory-hungry, so callers should run this off the request path (see
 * {@see GeneratePhotoThumbnailJob}).
 */
class Thumbnailer
{
    /** Longest side of a generated thumbnail. */
    public const MAX_EDGE = 800;

    public const QUALITY = 82;

    /**
     * Encoded JPEG bytes for a thumbnail of the image at $sourcePath, or null when
     * GD can't read the format (e.g. a HEIC that reached us without conversion).
     */
    public static function fromPath(string $sourcePath, int $maxEdge = self::MAX_EDGE): ?string
    {
        $info = @getimagesize($sourcePath);
        if (! $info) {
            return null;
        }

        $src = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => @imagecreatefromwebp($sourcePath),
            IMAGETYPE_GIF => @imagecreatefromgif($sourcePath),
            default => false,
        };
        if (! $src) {
            return null;
        }

        $src = self::applyExifOrientation($src, $sourcePath, $info[2] ?? null);

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $maxEdge / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        // Flatten transparency onto white so PNGs/WebP get a sane JPEG background.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

        ob_start();
        imagejpeg($dst, null, self::QUALITY);
        $data = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $data ?: null;
    }

    /** Width/height of an image, without decoding it. */
    public static function dimensions(string $path): array
    {
        $info = @getimagesize($path);
        if (! $info) {
            return [null, null];
        }

        return [$info[0] ?? null, $info[1] ?? null];
    }

    private static function applyExifOrientation(\GdImage $img, string $path, ?int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? 0;

        $rotated = match ($orientation) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };

        if ($rotated) {
            imagedestroy($img);

            return $rotated;
        }

        return $img;
    }
}
