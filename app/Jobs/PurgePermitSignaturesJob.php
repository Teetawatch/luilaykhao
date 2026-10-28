<?php

namespace App\Jobs;

use App\Models\BookingPassenger;
use App\Services\PermitSignatureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ลบภาพลายเซ็นบนแบบฟอร์มอุทยานเมื่อหมดหน้าที่ — ลายเซ็นเป็นของที่เอาไปปลอมเอกสาร
 * ได้ จึงถือไว้เท่าที่จำเป็น: ใบจองที่ยกเลิก/คืนเงินลบรอบถัดไป ทริปที่จบแล้วลบเมื่อพ้น
 * {@see RETENTION_DAYS} วันหลังวันกลับ (เผื่ออุทยานหรือประกันขอเอกสารย้อนหลัง)
 *
 * หลังลบ PDF ที่โหลดใหม่จะไม่มีลายเซ็น — ไฟล์ที่ยื่นอุทยานไปแล้วอยู่ที่อุทยาน
 */
class PurgePermitSignaturesJob implements ShouldQueue
{
    use Queueable;

    public const RETENTION_DAYS = 14;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(PermitSignatureService $signatures): void
    {
        $cutoff = now('Asia/Bangkok')->subDays(self::RETENTION_DAYS)->toDateString();
        $purged = 0;

        BookingPassenger::query()
            // ภาพลายเซ็น และภูมิลำเนา/โทรศัพท์บ้านที่ถามตอนเซ็น — ลบไปด้วยกัน
            ->where(fn (Builder $q) => $q->whereNotNull('permit_signature_path')->orWhereNotNull('permit_extra'))
            ->whereHas('booking', fn (Builder $booking) => $booking
                ->whereIn('status', ['cancelled', 'refunded', 'expired'])
                ->orWhereHas('schedule', fn (Builder $schedule) => $schedule
                    ->whereDate('return_date', '<', $cutoff)
                    ->orWhere(fn (Builder $oneDay) => $oneDay
                        ->whereNull('return_date')
                        ->whereDate('departure_date', '<', $cutoff))))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($signatures, &$purged) {
                foreach ($rows as $passenger) {
                    $signatures->clear($passenger);
                    $purged++;
                }
            });

        if ($purged > 0) {
            Log::info('PurgePermitSignaturesJob: ลบลายเซ็นแบบฟอร์มอุทยาน', ['passengers' => $purged]);
        }
    }
}
