<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ForceMajeureSeatHold;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * หน้า "ลูกค้าที่ถูกเลื่อนรอบ" ของแอดมิน — ทุกใบที่ถูกเลื่อนเพราะเหตุสุดวิสัย/คนไม่ครบ
 * ในหน้าเดียว ไม่ต้องรู้ก่อนว่ารอบเดิมคือรอบไหน
 *
 * หน้ารอบเดินทาง (ForceMajeureService::overview) ตอบได้แค่ "รอบนี้เลื่อนใครไปบ้าง"
 * ตัวนี้ตอบอีกสองคำถามที่หน้างานถามจริง: ใบนี้ไปอยู่รอบไหนแล้ว และรอบใหม่
 * กันที่นั่งไว้ให้ใครบ้าง — รอบที่กันเกินที่ว่างจริง (แอดมินย้ายคนอื่นเข้าไปทีหลัง)
 * จะโชว์ว่าเต็มทั้งที่ยังมีที่ว่าง ต้องเห็นตรงนี้ก่อนลูกค้าทักมา
 */
class PostponedBookingsBoard
{
    /** ใบที่ปิดเรื่องไปนานแล้วไม่ต้องรก — ใบที่ยังค้างอยู่แสดงเสมอไม่ว่าเก่าแค่ไหน */
    public const RESOLVED_LOOKBACK_DAYS = 60;

    /** @return array<string, mixed> */
    public function build(): array
    {
        $bookings = Booking::query()
            ->whereNotNull('force_majeure_at')
            ->where(fn ($q) => $q
                ->whereNull('force_majeure_resolved_at')
                ->orWhere('force_majeure_at', '>=', now()->subDays(self::RESOLVED_LOOKBACK_DAYS)))
            ->with([
                'user:id,name,phone,email',
                'schedule:id,trip_id,departure_date,departs_at,status',
                'forceMajeureSchedule:id,trip_id,departure_date,departs_at,status',
                'forceMajeureSchedule.trip:id,title',
            ])
            ->withCount('passengers')
            ->orderByDesc('force_majeure_at')
            ->orderBy('id')
            ->get();

        $holds = ForceMajeureSeatHold::whereIn('booking_id', $bookings->modelKeys())
            ->with('schedule:id,trip_id,departure_date,departs_at,status,total_seats,booked_seats')
            ->orderBy('id')
            ->get();
        $activeHoldIds = ForceMajeureSeatHold::active()
            ->whereKey($holds->modelKeys())
            ->pluck('id')
            ->flip();

        $rows = $bookings->map(fn (Booking $booking) => $this->bookingRow(
            $booking,
            $holds->where('booking_id', $booking->id)->values(),
            $activeHoldIds,
        ))->values();

        return [
            'counts' => [
                'total' => $rows->count(),
                'awaiting' => $rows->where('state', 'awaiting')->count(),
                'moved' => $rows->where('state', 'moved')->count(),
                'expired' => $rows->where('state', 'expired')->count(),
                'refund_requested' => $rows->where('state', 'refund_requested')->count(),
                'closed' => $rows->whereIn('state', ['refunded', 'cancelled'])->count(),
                'active_holds' => $activeHoldIds->count(),
            ],
            'rounds_with_holds' => $this->roundsWithHolds(),
            'bookings' => $rows,
        ];
    }

    /**
     * รอบที่ยังมีการกันที่นั่งอยู่ — พร้อมบอกว่ากันเกินที่ว่างจริงไหม
     *
     * @return array<int, array<string, mixed>>
     */
    private function roundsWithHolds(): array
    {
        $holds = ForceMajeureSeatHold::active()
            ->with([
                'booking:id,booking_ref,user_id',
                'booking.user:id,name,phone',
                'schedule.trip:id,title',
            ])
            ->orderBy('expires_at')
            ->get();

        return $holds->groupBy('schedule_id')
            ->map(function (Collection $group) {
                /** @var TripSchedule $schedule */
                $schedule = $group->first()->schedule;
                $held = (int) $group->sum('seat_count');
                $available = (int) $schedule->available_seats;

                return [
                    'schedule_id' => $schedule->id,
                    'trip_title' => $schedule->trip?->title,
                    'departure_date' => $schedule->departure_date?->toDateString(),
                    'departure_label' => $this->departureLabel($schedule),
                    'total_seats' => (int) $schedule->total_seats,
                    'booked_seats' => (int) $schedule->booked_seats,
                    'available_seats' => $available,
                    'held_seats' => $held,
                    // คนทั่วไปเห็นรอบนี้เต็ม และคนที่ถูกกันให้ก็เข้าไม่ได้ครบทุกกลุ่ม
                    'overcommitted' => $held > $available,
                    'holds' => $group->map(fn (ForceMajeureSeatHold $hold) => [
                        'id' => $hold->id,
                        'booking_ref' => $hold->booking?->booking_ref,
                        'customer_name' => $hold->booking?->user?->name,
                        'customer_phone' => $hold->booking?->user?->phone,
                        'seat_count' => (int) $hold->seat_count,
                        ...$this->expiry($hold, $schedule),
                    ])->values()->all(),
                ];
            })
            ->sortBy('departure_date')
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function bookingRow(Booking $booking, Collection $holds, Collection $activeHoldIds): array
    {
        $state = ForceMajeureService::stateOf($booking);
        $original = $booking->forceMajeureSchedule;

        return [
            'id' => $booking->id,
            'booking_ref' => $booking->booking_ref,
            'status' => $booking->status,
            'state' => $state,
            'kind' => $booking->postpone_kind ?: ForceMajeureService::KIND_FORCE_MAJEURE,
            'reason' => $booking->force_majeure_reason,
            'postponed_at' => $booking->force_majeure_at?->toISOString(),
            'customer_name' => $booking->user?->name,
            'customer_phone' => $booking->user?->phone,
            'customer_email' => $booking->user?->email,
            'passengers_count' => (int) $booking->passengers_count,
            'paid_amount' => (float) $booking->paid_amount,
            'trip_title' => $original?->trip?->title,
            'original' => $original ? [
                'schedule_id' => $original->id,
                'departure_date' => $original->departure_date?->toDateString(),
                'label' => $this->departureLabel($original),
            ] : null,
            'moved_to' => $state === 'moved' && $booking->schedule && $booking->schedule_id !== $booking->force_majeure_schedule_id
                ? [
                    'schedule_id' => $booking->schedule->id,
                    'departure_date' => $booking->schedule->departure_date?->toDateString(),
                    'label' => $this->departureLabel($booking->schedule),
                    'moved_at' => $booking->force_majeure_resolved_at?->toISOString(),
                ]
                : null,
            'until_label' => $booking->force_majeure_until ? ThaiDate::full($booking->force_majeure_until) : null,
            'decide_by_label' => $booking->postpone_decide_by ? ThaiDate::full($booking->postpone_decide_by) : null,
            'choose_url' => $state === 'awaiting' ? $booking->rescheduleUrl() : null,
            'holds' => $holds->map(fn (ForceMajeureSeatHold $hold) => [
                'id' => $hold->id,
                'schedule_id' => $hold->schedule_id,
                'departure_label' => $hold->schedule ? $this->departureLabel($hold->schedule) : null,
                'seat_count' => (int) $hold->seat_count,
                'state' => $this->holdState($hold, $booking, $activeHoldIds),
                'released_at' => $hold->released_at?->toISOString(),
                ...$hold->schedule ? $this->expiry($hold, $hold->schedule) : [],
            ])->values()->all(),
        ];
    }

    /** active = กันอยู่ · used = เจ้าของย้ายเข้ารอบนี้แล้ว · released · expired · void = ใบปิดเรื่องแล้ว */
    private function holdState(ForceMajeureSeatHold $hold, Booking $booking, Collection $activeHoldIds): string
    {
        if ($activeHoldIds->has($hold->id)) {
            return 'active';
        }
        if ($hold->released_at !== null) {
            return (int) $booking->schedule_id === (int) $hold->schedule_id ? 'used' : 'released';
        }

        return $hold->expires_at->isPast() ? 'expired' : 'void';
    }

    /** @return array{expires_at: string, expires_label: string, expires_after_departure: bool} */
    private function expiry(ForceMajeureSeatHold $hold, TripSchedule $schedule): array
    {
        return [
            'expires_at' => $hold->expires_at->toISOString(),
            'expires_label' => ThaiDate::shortTime($hold->expires_at->copy()->timezone(ForceMajeureService::TIMEZONE)).' น.',
            // กันข้ามเวลารถออก — ที่นั่งไม่มีวันกลับมาให้คนอื่นจองทันก่อนเดินทาง
            'expires_after_departure' => ($departs = $this->departsAt($schedule)) !== null
                && $hold->expires_at->gt($departs),
        ];
    }

    /**
     * เวลาออกเดินทางเป็นเวลาจริง — departs_at เก็บเวลาไทยในคอลัมน์ชนิด UTC
     * จึงต้องอ่านเป็นเวลาไทยก่อนเทียบ ไม่มีเวลาออกรถ = ต้นวันเดินทาง
     */
    private function departsAt(TripSchedule $schedule): ?Carbon
    {
        if ($schedule->departs_at) {
            return Carbon::parse($schedule->departs_at->format('Y-m-d H:i:s'), ForceMajeureService::TIMEZONE);
        }

        return $schedule->departure_date
            ? Carbon::parse($schedule->departure_date->toDateString(), ForceMajeureService::TIMEZONE)
            : null;
    }

    private function departureLabel(TripSchedule $schedule): string
    {
        return '#'.$schedule->id.' · '.($schedule->departure_date ? ThaiDate::full($schedule->departure_date) : 'ไม่มีวันเดินทาง')
            .($schedule->status === 'cancelled' ? ' (ยกเลิก)' : '');
    }
}
