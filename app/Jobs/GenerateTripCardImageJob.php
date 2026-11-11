<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Support\MediaDisk;
use App\Support\Thumbnailer;
use App\Support\TripListCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * ย่อรูปการ์ดของทริป (thumbnail_image หรือรูปปก) เหลือด้านยาว 1200px ([MAX_EDGE]) — การ์ดในหน้า
 * แรกของแอป/เว็บเคยโหลดรูปขนาดเต็มที่แอดมินอัปโหลด ทีละหลาย MB
 *
 * ย่อเฉพาะรูปที่อยู่บน storage ของเราเอง (อ่านจาก disk ตรง ๆ) — URL ภายนอกไม่
 * ดาวน์โหลด การ์ดจะใช้รูปเดิมไปตามปกติ
 */
class GenerateTripCardImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ด้านยาวของรูปการ์ด — การ์ดกว้างเกือบเต็มจอบนมือถือ 3x ต้องใช้ราว 1,100px */
    public const MAX_EDGE = 1200;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public int $tripId) {}

    public function handle(): void
    {
        $trip = Trip::find($this->tripId);
        if (! $trip) {
            return;
        }

        $source = $trip->cardImageSource();
        if (! $source || $trip->card_image_source === $source) {
            return; // ไม่มีรูป หรือย่อรูปนี้ไว้แล้ว
        }

        $diskName = MediaDisk::name();
        $disk = Storage::disk($diskName);
        $path = self::diskPathFor($source, $disk->url(''));
        if ($path === null || ! $disk->exists($path)) {
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'card_');
        if ($tmp === false) {
            return;
        }

        try {
            $stream = $disk->readStream($path);
            if (! $stream) {
                return;
            }
            $local = fopen($tmp, 'w');
            stream_copy_to_stream($stream, $local);
            fclose($local);
            fclose($stream);

            $data = Thumbnailer::fromPath($tmp, self::MAX_EDGE);
            if (! $data) {
                return; // GD อ่านไม่ออก (เช่น HEIC) — การ์ดใช้รูปเต็มต่อไป
            }

            $cardPath = 'trips/'.$trip->id.'/card-'.substr(md5($source), 0, 12).'.jpg';
            $disk->put($cardPath, $data, ['visibility' => 'public', 'ContentType' => 'image/jpeg']);

            $trip->forceFill([
                'card_image' => $disk->url($cardPath),
                'card_image_source' => $source,
            ])->saveQuietly();
            // saveQuietly ไม่ยิง event — ทิ้งแคชรายการทริปเองให้การ์ดได้รูปใหม่ทันที
            TripListCache::flush();
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * path บน disk ของรูปที่เก็บเป็น URL เต็ม (หรือ path ตรง ๆ แบบเก่า) — null = รูปอยู่ที่อื่น
     */
    public static function diskPathFor(string $source, string $diskBaseUrl): ?string
    {
        $base = rtrim($diskBaseUrl, '/').'/';
        if ($base !== '/' && str_starts_with($source, $base)) {
            $path = substr($source, strlen($base));
        } elseif (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
            return null; // รูปจากที่อื่น
        } else {
            $path = $source; // ระเบียนเก่าที่เก็บ path ตรง ๆ
        }

        $path = ltrim(rawurldecode((string) strtok($path, '?#')), '/');

        return $path !== '' && ! str_contains($path, '..') ? $path : null;
    }
}
