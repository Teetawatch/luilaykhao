<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\FlexiDepartureConsent;
use App\Models\FlexiDepartureOffer;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ระบบ Flexi-Price (Go Together) — เมื่อรอบเดินทางมีผู้จองไม่ถึงขั้นต่ำที่รถออกคุ้ม
 * และใกล้ถึงกำหนดเดินทาง ผู้จัดยื่นข้อเสนอให้ผู้ที่จองแล้วช่วยกันจ่ายส่วนต่าง
 * ค่ารถท่านละ X บาท แทนการยกเลิกทริป ถ้า "ทุกคน" กดยอมรับ ทริปเดินหน้าต่อได้
 * ตามกำหนดเดิม (เก็บส่วนต่างในวันเดินทาง — ระบบยังไม่มี card gateway)
 */
class FlexiDepartureService
{
    /**
     * ผู้จัดสร้างข้อเสนอสำหรับรอบที่คนไม่ครบ — สร้างการตอบรับ (pending) ให้ทุก
     * การจองที่ยืนยันแล้ว และยิงแจ้งเตือนหาเจ้าของการจองแต่ละคน
     */
    public function createOffer(
        TripSchedule $schedule,
        float $surchargePerPerson,
        CarbonInterface $respondBy,
        ?string $reason = null,
        ?User $creator = null,
    ): FlexiDepartureOffer {
        if ($surchargePerPerson <= 0) {
            throw new \Exception('ส่วนต่างต่อท่านต้องมากกว่า 0');
        }

        if ($schedule->is_charter) {
            throw new \Exception('รอบเหมาคันไม่ต้องใช้ Flexi-Price');
        }

        if ($schedule->status === 'cancelled') {
            throw new \Exception('รอบนี้ถูกยกเลิกไปแล้ว');
        }

        if ($respondBy->isPast()) {
            throw new \Exception('กำหนดเวลาตอบรับต้องอยู่ในอนาคต');
        }

        if ($this->activeOffer($schedule) !== null) {
            throw new \Exception('รอบนี้มีข้อเสนอ Flexi-Price ที่ยังเปิดอยู่แล้ว');
        }

        $bookings = $this->confirmedBookings($schedule);
        if ($bookings->isEmpty()) {
            throw new \Exception('รอบนี้ยังไม่มีการจองที่ยืนยันแล้ว');
        }

        $offer = DB::transaction(function () use ($schedule, $surchargePerPerson, $respondBy, $reason, $creator, $bookings) {
            $offer = FlexiDepartureOffer::create([
                'schedule_id' => $schedule->id,
                'surcharge_per_person' => $surchargePerPerson,
                'reason' => $reason,
                'status' => FlexiDepartureOffer::STATUS_PENDING,
                'respond_by' => $respondBy,
                'created_by' => $creator?->id,
            ]);

            foreach ($bookings as $booking) {
                FlexiDepartureConsent::create([
                    'offer_id' => $offer->id,
                    'booking_id' => $booking->id,
                    'status' => FlexiDepartureConsent::STATUS_PENDING,
                    'surcharge_total' => $this->surchargeFor($booking, $surchargePerPerson),
                ]);
            }

            return $offer;
        });

        foreach ($bookings as $booking) {
            $this->notifyOfferCreated($offer, $schedule, $booking);
        }

        return $offer;
    }

    /**
     * เจ้าของการจองตอบรับ/ปฏิเสธข้อเสนอ แล้วประเมินสถานะรวมของข้อเสนอใหม่:
     * ทุกคนยอมรับ → confirmed (ทริปไปต่อ), มีคนปฏิเสธ → declined
     */
    public function respond(Booking $booking, bool $accept): FlexiDepartureConsent
    {
        $offer = $this->activeOfferForBooking($booking);
        if ($offer === null) {
            throw new \Exception('ไม่มีข้อเสนอ Flexi-Price ที่เปิดให้ตอบรับสำหรับการจองนี้');
        }

        $consent = $offer->consents()->where('booking_id', $booking->id)->first();
        if ($consent === null) {
            throw new \Exception('ไม่พบรายการตอบรับสำหรับการจองนี้');
        }

        if ($consent->status !== FlexiDepartureConsent::STATUS_PENDING) {
            throw new \Exception('คุณตอบรับข้อเสนอนี้ไปแล้ว');
        }

        $consent->update([
            'status' => $accept
                ? FlexiDepartureConsent::STATUS_ACCEPTED
                : FlexiDepartureConsent::STATUS_DECLINED,
            'responded_at' => now(),
        ]);

        $this->evaluate($offer->fresh());

        return $consent->fresh();
    }

    /**
     * ประเมินข้อเสนอหลังมีการตอบรับ:
     *   - มีคนปฏิเสธ → declined (ทริปไปต่อแบบนี้ไม่ได้ ต้องให้ผู้จัดตัดสินใจ)
     *   - ทุกคนยอมรับ → confirmed: บันทึกส่วนต่างลงการจอง + แจ้งทุกคนว่าไปต่อ
     */
    public function evaluate(FlexiDepartureOffer $offer): void
    {
        if ($offer->status !== FlexiDepartureOffer::STATUS_PENDING) {
            return;
        }

        $consents = $offer->consents()->with('booking.user')->get();

        if ($consents->contains(fn ($c) => $c->status === FlexiDepartureConsent::STATUS_DECLINED)) {
            $offer->update([
                'status' => FlexiDepartureOffer::STATUS_DECLINED,
                'resolved_at' => now(),
            ]);
            $this->notifyResolved($offer, FlexiDepartureOffer::STATUS_DECLINED);

            return;
        }

        $allAccepted = $consents->isNotEmpty()
            && $consents->every(fn ($c) => $c->status === FlexiDepartureConsent::STATUS_ACCEPTED);

        if ($allAccepted) {
            DB::transaction(function () use ($offer, $consents) {
                $offer->update([
                    'status' => FlexiDepartureOffer::STATUS_CONFIRMED,
                    'confirmed_at' => now(),
                    'resolved_at' => now(),
                ]);

                foreach ($consents as $consent) {
                    $consent->booking?->update(['flexi_surcharge' => $consent->surcharge_total]);
                }
            });

            $this->notifyResolved($offer, FlexiDepartureOffer::STATUS_CONFIRMED);
        }
    }

    /**
     * งานตามกำหนดเวลา: ปิดข้อเสนอที่เลยเส้นตายแต่ยังไม่ครบทุกคน → expired
     */
    public function expireStale(): int
    {
        $expired = 0;

        FlexiDepartureOffer::where('status', FlexiDepartureOffer::STATUS_PENDING)
            ->where('respond_by', '<', now())
            ->with('schedule.trip')
            ->chunkById(100, function ($offers) use (&$expired) {
                foreach ($offers as $offer) {
                    $offer->update([
                        'status' => FlexiDepartureOffer::STATUS_EXPIRED,
                        'resolved_at' => now(),
                    ]);
                    $this->notifyResolved($offer, FlexiDepartureOffer::STATUS_EXPIRED);
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * ข้อมูลสรุปข้อเสนอสำหรับแอป: ข้อเสนอที่เปิดอยู่/ล่าสุด + การตอบรับของการจองนี้
     * + ความคืบหน้า (ตอบรับแล้วกี่คนจากทั้งหมด) คืน null เมื่อไม่มีข้อเสนอเลย
     *
     * @return array<string, mixed>|null
     */
    public function overview(Booking $booking): ?array
    {
        $offer = $this->activeOfferForBooking($booking)
            ?? $this->latestOfferForBooking($booking);

        if ($offer === null) {
            return null;
        }

        $consents = $offer->consents()->get();
        $mine = $consents->firstWhere('booking_id', $booking->id);

        return [
            'id' => $offer->id,
            'status' => $offer->status,
            'is_open' => $offer->isOpen(),
            'surcharge_per_person' => (float) $offer->surcharge_per_person,
            'my_surcharge_total' => $mine ? (float) $mine->surcharge_total : null,
            'reason' => $offer->reason,
            'respond_by' => $offer->respond_by?->toISOString(),
            'my_consent' => $mine?->status,
            'progress' => [
                'total' => $consents->count(),
                'accepted' => $consents->where('status', FlexiDepartureConsent::STATUS_ACCEPTED)->count(),
                'declined' => $consents->where('status', FlexiDepartureConsent::STATUS_DECLINED)->count(),
                'pending' => $consents->where('status', FlexiDepartureConsent::STATUS_PENDING)->count(),
            ],
        ];
    }

    /** ข้อเสนอที่ยังเปิดอยู่ของรอบเดินทาง (pending + ยังไม่เลยเส้นตาย) */
    public function activeOffer(TripSchedule $schedule): ?FlexiDepartureOffer
    {
        return FlexiDepartureOffer::where('schedule_id', $schedule->id)
            ->where('status', FlexiDepartureOffer::STATUS_PENDING)
            ->where('respond_by', '>', now())
            ->latest('id')
            ->first();
    }

    private function activeOfferForBooking(Booking $booking): ?FlexiDepartureOffer
    {
        return FlexiDepartureOffer::where('schedule_id', $booking->schedule_id)
            ->where('status', FlexiDepartureOffer::STATUS_PENDING)
            ->where('respond_by', '>', now())
            ->whereHas('consents', fn ($q) => $q->where('booking_id', $booking->id))
            ->latest('id')
            ->first();
    }

    private function latestOfferForBooking(Booking $booking): ?FlexiDepartureOffer
    {
        return FlexiDepartureOffer::where('schedule_id', $booking->schedule_id)
            ->whereHas('consents', fn ($q) => $q->where('booking_id', $booking->id))
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function confirmedBookings(TripSchedule $schedule)
    {
        return Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where('is_join_trip', false)
            ->withCount('passengers')
            ->get();
    }

    private function surchargeFor(Booking $booking, float $perPerson): float
    {
        $pax = (int) ($booking->passengers_count ?? $booking->passengers()->count());

        return round($perPerson * max(1, $pax), 2);
    }

    private function notifyOfferCreated(FlexiDepartureOffer $offer, TripSchedule $schedule, Booking $booking): void
    {
        if (! $booking->user_id) {
            return;
        }

        $total = $this->surchargeFor($booking, (float) $offer->surcharge_per_person);
        $trip = $schedule->trip;
        $title = $trip?->title ?? 'ทริปของคุณ';

        try {
            SmartNotification::send(
                $booking->user_id,
                'flexi_offer',
                'ไปต่อกันไหม? 🚐',
                "รอบ{$title} วันที่ ".ThaiDate::full($schedule->departure_date)
                    .' มีผู้เดินทางไม่ครบ หากช่วยกันจ่ายส่วนต่างค่ารถเพิ่มท่านละ ฿'
                    .number_format((float) $offer->surcharge_per_person)
                    .' (การจองของคุณ ฿'.number_format($total).') ทริปจะออกเดินทางได้ตามกำหนดเดิม ยินดีไปต่อหรือไม่?',
                [
                    'route' => 'flexi_offer',
                    'booking_ref' => $booking->booking_ref,
                    'offer_id' => $offer->id,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('FlexiDepartureService: offer notification failed — '.$e->getMessage());
        }
    }

    private function notifyResolved(FlexiDepartureOffer $offer, string $status): void
    {
        $schedule = $offer->schedule()->with('trip')->first();
        if ($schedule === null) {
            return;
        }

        $trip = $schedule->trip;
        $title = $trip?->title ?? 'ทริปของคุณ';
        $dateText = ThaiDate::full($schedule->departure_date);

        [$pushTitle, $body] = match ($status) {
            FlexiDepartureOffer::STATUS_CONFIRMED => [
                'ทริปไปต่อแน่นอน! 🎉',
                "เยี่ยม! ทุกท่านยินดีไปต่อ รอบ{$title} วันที่ {$dateText} ออกเดินทางตามกำหนดเดิม ส่วนต่างค่ารถเก็บในวันเดินทาง",
            ],
            FlexiDepartureOffer::STATUS_DECLINED => [
                'อัปเดตรอบเดินทาง',
                "รอบ{$title} วันที่ {$dateText} มีผู้ไม่สะดวกจ่ายส่วนต่าง ทีมงานจะติดต่อกลับเรื่องทางเลือกและการคืนเงินโดยเร็ว",
            ],
            FlexiDepartureOffer::STATUS_EXPIRED => [
                'อัปเดตรอบเดินทาง',
                "รอบ{$title} วันที่ {$dateText} หมดเวลาตอบรับข้อเสนอไปต่อแล้ว ทีมงานจะติดต่อกลับเรื่องทางเลือกและการคืนเงินโดยเร็ว",
            ],
            default => ['อัปเดตรอบเดินทาง', "มีอัปเดตเกี่ยวกับรอบ{$title} วันที่ {$dateText}"],
        };

        $bookingRefs = FlexiDepartureConsent::where('offer_id', $offer->id)
            ->with('booking:id,user_id,booking_ref')
            ->get();

        foreach ($bookingRefs as $consent) {
            $booking = $consent->booking;
            if (! $booking?->user_id) {
                continue;
            }

            try {
                SmartNotification::send(
                    $booking->user_id,
                    'flexi_offer_'.$status,
                    $pushTitle,
                    $body,
                    [
                        'route' => 'booking',
                        'booking_ref' => $booking->booking_ref,
                        'offer_id' => $offer->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('FlexiDepartureService: resolution notification failed — '.$e->getMessage());
            }
        }
    }
}
