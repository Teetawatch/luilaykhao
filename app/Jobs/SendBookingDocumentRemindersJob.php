<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\BookingDocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ทวงเอกสารแนบที่ทริปบังคับ (ใบรับรองแพทย์ บัตรประชาชนของ Border Pass ฯลฯ)
 * เป็น push ก่อนวันเดินทาง
 *
 * ทำไมต้องมี: ลูกค้าจองก่อนแล้วแนบทีหลังได้ (หลายคนยังไม่มีเอกสารในมือตอนจอง)
 * ถ้าไม่มีใครทวง เอกสารที่ขาดจะโผล่ให้เห็นอีกทีก็วันที่ทีมงานต้องใช้จริง
 *
 * ยิงเฉพาะรอบที่ออกเดินทางอีก DAYS_BEFORE วันพอดี — เตือนไม่เกิน 3 ครั้งต่อใบจอง
 * (แนวเดียวกับ SendTravelDocumentRemindersJob) ส่งถึงเจ้าของใบจอง และเพื่อนที่ผูก
 * ชื่อในแอปที่ยังขาดเอกสารของตัวเอง ซึ่งแนบเองได้ในหน้าเอกสารแนบ
 */
class SendBookingDocumentRemindersJob implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'booking_documents_needed';

    /** จำนวนวันก่อนเดินทางที่จะเตือน */
    public const DAYS_BEFORE = [7, 3, 1];

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BookingDocumentService $documents): void
    {
        $today = now('Asia/Bangkok')->toDateString();
        $reminded = 0;

        foreach (self::DAYS_BEFORE as $daysBefore) {
            $targetDate = now('Asia/Bangkok')->addDays($daysBefore)->toDateString();

            $scheduleIds = TripSchedule::query()
                ->departingOn($targetDate)
                ->where('status', '!=', 'cancelled')
                // รอบที่ยังไม่ครบขั้นต่ำ — ทวงเอกสารตอนนี้ = บอกลูกค้าว่ารอบออกแน่แล้ว
                ->travelMessagesAllowed()
                ->pluck('id');

            if ($scheduleIds->isEmpty()) {
                continue;
            }

            Booking::query()
                ->whereIn('schedule_id', $scheduleIds)
                ->where('status', 'confirmed')
                ->whereNotNull('user_id')
                ->where(fn ($q) => $q->whereNull('force_majeure_at')->orWhereNotNull('force_majeure_resolved_at'))
                ->with(['schedule.trip', 'passengers', 'documents'])
                ->orderBy('id')
                ->chunkById(200, function ($bookings) use ($documents, $daysBefore, $today, &$reminded) {
                    foreach ($bookings as $booking) {
                        if ($this->remind($booking, $documents->missingByPassenger($booking), $daysBefore, $today)) {
                            $reminded++;
                        }
                    }
                });
        }

        if ($reminded > 0) {
            Log::info('SendBookingDocumentRemindersJob: reminded '.$reminded.' bookings');
        }
    }

    /** @param  array<int, array<int, string>>  $missing  passenger_id → ชื่อเอกสาร */
    private function remind(Booking $booking, array $missing, int $daysBefore, string $today): bool
    {
        // งานถูกลองซ้ำได้ — อย่าให้ใบจองเดียวกันได้ข้อความสองรอบในวันเดียว
        if ($missing === [] || ! Cache::add("booking-documents-reminder:{$booking->id}:{$today}", true, now()->addDay())) {
            return false;
        }

        $trip = $booking->schedule?->trip?->title ?? 'ทริปของคุณ';
        $when = $daysBefore === 1 ? 'พรุ่งนี้' : "ในอีก {$daysBefore} วัน";
        $data = ['booking_ref' => $booking->booking_ref, 'route' => 'booking_documents'];

        $going = $booking->passengers->whereNull('not_going_at')->count();
        $labels = collect($missing)->flatten()->unique()->implode(', ');
        $who = $going > 1 ? ' ของผู้เดินทาง '.count($missing).' จาก '.$going.' คน' : '';

        SmartNotification::send(
            $booking->user_id,
            self::TYPE,
            'อย่าลืมแนบเอกสารก่อนเดินทาง',
            "{$trip} ออกเดินทาง{$when} ยังขาด{$labels}{$who} — แตะเพื่อแนบได้เลย ถ่ายรูปจากมือถือก็ได้",
            $data,
        );

        BookingMember::where('booking_id', $booking->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->whereIn('passenger_id', array_keys($missing))
            ->where('user_id', '!=', $booking->user_id)
            ->get(['user_id', 'passenger_id'])
            ->unique('user_id')
            ->each(fn (BookingMember $member) => SmartNotification::send(
                $member->user_id,
                self::TYPE,
                'อย่าลืมแนบเอกสารก่อนเดินทาง',
                "{$trip} ออกเดินทาง{$when} ยังขาด".implode(', ', $missing[$member->passenger_id])
                    .'ของคุณ — แตะเพื่อแนบได้เลย',
                $data,
            ));

        return true;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendBookingDocumentRemindersJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
