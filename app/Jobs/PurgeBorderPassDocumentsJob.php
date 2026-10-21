<?php

namespace App\Jobs;

use App\Models\BookingDocument;
use App\Services\BookingDocumentService;
use App\Support\TripDocumentRequirements;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ลบสำเนาบัตรประชาชนที่แนบไว้ทำ Border Pass เมื่อหมดหน้าที่แล้ว
 *
 * ด่านบังคับให้ใช้บัตรหน้า/หลังเป็นหลักฐาน เราจึงต้องเก็บ แต่เป็นข้อมูลที่ถ้าหลุด
 * เอาไปสวมรอยได้ทันที จึงถือไว้เท่าที่จำเป็น: ใบจองที่ยกเลิกแล้วลบรอบถัดไปเลย
 * ส่วนทริปที่จบแล้วลบเมื่อพ้น {@see RETENTION_DAYS} วันหลังวันกลับ (เผื่อเวลา
 * เคลียร์เรื่องที่ด่านหรือประกันถามย้อนหลัง)
 *
 * คนที่เปลี่ยนจาก Border Pass ไปใช้พาสปอร์ตถูกลบทันทีอยู่แล้ว (BookingPassenger::booted)
 */
class PurgeBorderPassDocumentsJob implements ShouldQueue
{
    use Queueable;

    /** เก็บไว้อีกกี่วันหลังวันกลับ — ตัวเลขนี้แสดงให้ลูกค้าเห็นตอนแนบไฟล์ด้วย */
    public const RETENTION_DAYS = 7;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(BookingDocumentService $documents): void
    {
        $cutoff = now('Asia/Bangkok')->subDays(self::RETENTION_DAYS)->toDateString();
        $deleted = 0;

        BookingDocument::query()
            ->whereIn('requirement_key', TripDocumentRequirements::BORDER_PASS_KEYS)
            ->whereHas('booking', fn (Builder $booking) => $booking
                ->where('status', 'cancelled')
                ->orWhereHas('schedule', fn (Builder $schedule) => $schedule
                    ->whereDate('return_date', '<', $cutoff)
                    ->orWhere(fn (Builder $oneDay) => $oneDay
                        ->whereNull('return_date')
                        ->whereDate('departure_date', '<', $cutoff))))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($documents, &$deleted) {
                foreach ($rows as $document) {
                    $documents->delete($document);
                    $deleted++;
                }
            });

        if ($deleted > 0) {
            Log::info('PurgeBorderPassDocumentsJob: ลบสำเนาบัตรประชาชน Border Pass', ['files' => $deleted]);
        }
    }
}
