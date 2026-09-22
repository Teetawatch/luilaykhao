<?php

namespace App\Jobs;

use App\Models\VehicleLocation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ลบพิกัดรถที่เก่าเกินกำหนด
 *
 * ตารางนี้โตเร็วกว่าตารางอื่นทั้งระบบ: ตอนวิ่งเก็บคน มือถือของสตาฟส่งทุก 12
 * วินาที = ~300 แถวต่อชั่วโมงต่อคัน และไม่เคยมีอะไรลบมันเลยตั้งแต่วันแรก
 *
 * สิ่งที่ยังต้องใช้จริงคือ "ตอนนี้รถอยู่ไหน" (แถวล่าสุด) กับการย้อนดูเส้นทางของ
 * รอบที่เพิ่งผ่านไป — ทั้งคู่อยู่ในกรอบไม่กี่สัปดาห์ ส่วนพิกัดของทริปเมื่อปีที่แล้ว
 * ไม่มีหน้าจอไหนเปิดดูอีกแล้ว เก็บไว้มีแต่ทำให้ backup ใหญ่ขึ้นทุกคืน
 */
class PruneVehicleLocationsJob implements ShouldQueue
{
    use Queueable;

    /** เก็บพิกัดย้อนหลังกี่วัน — พอสำหรับย้อนดูเส้นทางและสอบสวนเหตุการณ์ */
    public const RETENTION_DAYS = 90;

    /** ลบทีละกี่แถว — เล็กพอที่จะไม่ล็อกตารางค้างระหว่างที่รถกำลังส่งพิกัดเข้ามา */
    public const CHUNK = 2000;

    public int $tries = 1;

    public function __construct(public ?int $days = null) {}

    public function handle(): int
    {
        $deleted = self::prune($this->days ?? self::RETENTION_DAYS);

        if ($deleted > 0) {
            Log::info('PruneVehicleLocationsJob completed', ['deleted' => $deleted]);
        }

        return $deleted;
    }

    /**
     * ลบเป็นก้อน ๆ จนหมด แล้วคืนจำนวนแถวที่ลบไป
     *
     * ไล่ด้วย id ทีละก้อนแทน DELETE ... LIMIT เพราะ Postgres ไม่รองรับไวยากรณ์นั้น
     * (ระบบมีแผนย้ายจาก MySQL ไป Postgres — ดู reference ของการย้าย)
     */
    public static function prune(int $days, ?callable $onChunk = null): int
    {
        $cutoff = Carbon::now()->subDays($days);
        $deleted = 0;

        while (true) {
            $ids = VehicleLocation::where('recorded_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += VehicleLocation::whereIn('id', $ids)->delete();

            if ($onChunk) {
                $onChunk($deleted);
            }

            if ($ids->count() < self::CHUNK) {
                break;
            }
        }

        return $deleted;
    }

    /** จำนวนแถวที่จะถูกลบถ้ารันตอนนี้ — ใช้ดูก่อนลงมือบนเครื่องจริง */
    public static function pending(int $days): int
    {
        return VehicleLocation::where('recorded_at', '<', Carbon::now()->subDays($days))->count();
    }
}
