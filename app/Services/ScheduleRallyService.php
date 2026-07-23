<?php

namespace App\Services;

use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "ช่วยกันเปิดรอบ" — รอบที่ยังไม่ถึงขั้นการันตีออกเดินทางและใกล้ถึงวันเดินทาง
 * จะถูกชวนให้คนที่จองแล้วช่วยกันหาเพื่อนมาเพิ่ม
 *
 * เหตุผลที่ได้ผล: คนที่จองไปแล้วอยากให้รอบออกมากกว่าเราเสียอีก เพราะถ้าไม่ครบ
 * ทริปของเขาเองจะถูกยกเลิก แรงจูงใจตรงนี้แรงกว่าส่วนลดใด ๆ
 *
 * ตั้งใจไม่ผูกส่วนลดใหม่เข้ามาเอง — ใช้โปรแกรมแนะนำเพื่อนที่มีอยู่แล้ว
 * (แต้มสะสมทั้งสองฝ่ายเมื่อเพื่อนใหม่จองทริปแรกสำเร็จ) การสร้างส่วนลดใหม่
 * เป็นการตัดสินใจทางธุรกิจที่เจ้าของต้องเป็นคนกำหนด
 */
class ScheduleRallyService
{
    /**
     * เริ่มชวนเมื่อเหลือถึงวันเดินทางไม่เกินกี่วัน — ไกลกว่านี้ยังไม่เร่งด่วน
     * และรอบมักเต็มเองอยู่แล้ว
     */
    public const RALLY_WINDOW_DAYS = 21;

    /**
     * @return array<string, mixed>
     */
    public function forSchedule(TripSchedule $schedule, ?User $user = null): array
    {
        $status = $schedule->departureStatus();
        $seatsNeeded = $schedule->seatsToGuarantee();
        $daysLeft = $this->daysUntilDeparture($schedule);

        $notApplicable = fn (string $reason) => [
            'active' => false,
            'reason' => $reason,
            'status' => $status,
            'seats_needed' => $seatsNeeded,
            'days_left' => $daysLeft,
        ];

        // เหมาลำไม่ต้องพึ่งจำนวนคน จึงไม่มีอะไรให้ช่วยกันเปิด
        if ($schedule->is_charter || $status === null) {
            return $notApplicable('charter');
        }

        if ($status === TripSchedule::STATUS_GUARANTEED) {
            return $notApplicable('already_guaranteed');
        }

        if ($daysLeft === null || $daysLeft < 0) {
            return $notApplicable('departed');
        }

        if ($daysLeft > self::RALLY_WINDOW_DAYS) {
            return $notApplicable('too_early');
        }

        // ที่นั่งที่ยังขายได้จริง — ชวนคนมาเกินจำนวนที่ว่างไม่ได้
        $available = max(0, (int) $schedule->total_seats - (int) $schedule->booked_seats);

        if ($available <= 0) {
            return $notApplicable('no_seats_left');
        }

        return [
            'active' => true,
            'reason' => null,
            'status' => $status,
            'seats_needed' => min($seatsNeeded, $available),
            'seats_available' => $available,
            'days_left' => $daysLeft,
            'guarantee_min_seats' => TripSchedule::guaranteeMinSeats(),
            'booked_seats' => (int) $schedule->booked_seats,
            'headline' => $this->headline($seatsNeeded, $daysLeft),
            'share_url' => $this->shareUrl($schedule, $user),
            'share_message' => $this->shareMessage($schedule, $seatsNeeded, $user),
        ];
    }

    /** จำนวนวันจากวันนี้ถึงวันเดินทาง (null เมื่อไม่มีวันเดินทาง) */
    private function daysUntilDeparture(TripSchedule $schedule): ?int
    {
        $departure = $schedule->departure_date;

        if (! $departure) {
            return null;
        }

        $today = Carbon::now('Asia/Bangkok')->startOfDay();

        return $today->diffInDays($departure->copy()->startOfDay(), false);
    }

    private function headline(int $seatsNeeded, int $daysLeft): string
    {
        if ($daysLeft === 0) {
            return "รอบนี้ออกเดินทางวันนี้ ยังขาดอีก {$seatsNeeded} ที่นั่ง";
        }

        return "ขาดอีก {$seatsNeeded} ที่นั่ง รอบนี้ก็ออกแน่นอน · เหลือเวลา {$daysLeft} วัน";
    }

    /**
     * ลิงก์ชวนเพื่อน — พาไปที่หน้าทริปพร้อมระบุรอบ และแนบโค้ดแนะนำเพื่อนของ
     * ผู้ชวนไว้ ถ้าเพื่อนเป็นลูกค้าใหม่ทั้งคู่จะได้แต้มตามโปรแกรมเดิม
     */
    private function shareUrl(TripSchedule $schedule, ?User $user): string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $slug = $schedule->trip?->slug ?? '';

        $query = ['schedule' => $schedule->id];

        if ($user?->referral_code) {
            $query['ref'] = Str::upper($user->referral_code);
        }

        return "{$base}/trips/{$slug}?".http_build_query($query);
    }

    private function shareMessage(TripSchedule $schedule, int $seatsNeeded, ?User $user): string
    {
        $title = $schedule->trip?->title ?? 'ทริป';
        $date = $schedule->departure_date
            ? $schedule->departure_date->locale('th')->translatedFormat('j M')
            : '';

        return "ไป{$title}"
            .($date !== '' ? " วันที่ {$date}" : '')
            ." กันไหม? 🏕️\nรอบนี้ขาดอีก {$seatsNeeded} คนก็ออกแน่นอนแล้ว\n"
            .$this->shareUrl($schedule, $user);
    }
}
