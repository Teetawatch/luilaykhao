<?php

namespace App\Support;

use App\Models\LoyaltyAccount;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * แคชคำตอบของรายการทริปสาธารณะ (หน้าแรกของแอปและเว็บ) — ทุกคนเห็นเหมือนกัน
 * ยกเว้นเวลาเปิดจองที่เลื่อนตามระดับสมาชิก (แยกคีย์ตามระดับ) แต่ทุกครั้งที่ใคร
 * เปิดแอปเคยต้องนับที่นั่ง ยอดจอง และรีวิวของทุกทริปใหม่หมด
 *
 * ถ้าเพิ่ม field ที่ขึ้นกับตัวผู้ชมลงใน TripResource/TripScheduleResource ต้องเพิ่ม
 * มันลงในคีย์ด้วย ไม่งั้นคนหนึ่งจะเห็นค่าของอีกคน
 *
 * อายุสั้น (TTL) เพราะ "เหลือกี่ที่" / flash sale เปลี่ยนตามเวลา และล้างทั้งชุดทันที
 * เมื่อทริป รอบ จุดขึ้นรถ หรือแคมเปญถูกแก้ ([flush], ผูกไว้ใน AppServiceProvider)
 * — การจองใหม่ก็ล้างด้วย เพราะ syncBookedSeats เขียนลงรอบ
 *
 * เก็บเป็น array ล้วน (ผ่าน JSON) ไม่ใช่ Resource/Collection — ของพวกนั้น
 * serialize ลง cache แล้วอ่านกลับมาไม่เหมือนเดิม
 */
final class TripListCache
{
    public const TTL_SECONDS = 60;

    private const VERSION_KEY = 'trip-lists:version';

    /**
     * @param  Closure(): JsonResponse  $build
     */
    public static function respond(string $name, Request $request, Closure $build): JsonResponse
    {
        $query = $request->query();
        self::sortRecursive($query);

        $key = implode(':', [
            'trip-lists',
            'v'.self::version(),
            $name,
            app()->getLocale(),
            // ส่วนเดียวของรายการที่ขึ้นกับผู้ชม: booking_opens_at ของแต่ละรอบเลื่อนเร็ว
            // ขึ้นตามระดับสมาชิก (TripScheduleResource) — แยกแคชตามระดับ ไม่ใช่ตามคน
            LoyaltyAccount::tierForUser($request->user('sanctum')?->id),
            md5((string) json_encode($query)),
        ]);

        $payload = Cache::remember($key, self::TTL_SECONDS, fn () => $build()->getData(true));

        return response()->json($payload);
    }

    /** ทิ้งแคชรายการทริปทั้งชุด — เปลี่ยนเลขรุ่น คีย์เก่าหมดอายุไปเองตาม TTL */
    public static function flush(): void
    {
        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    private static function sortRecursive(array &$value): void
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
