<?php

namespace App\Services;

use App\Models\Booking;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * หน้าการ์ดนับถอยหลังสาธารณะ /s/{token} — ปลายทางของลิงก์ที่ลูกค้าแชร์ลงฟีด
 *
 * **กฎข้อเดียวที่สำคัญที่สุดของไฟล์นี้: ทุกอย่างที่คืนออกไปจะถูกโพสต์สาธารณะ**
 * จึงคืนเฉพาะข้อมูลของ *ทริป* (ซึ่งเปิดให้คนทั่วไปดูอยู่แล้วที่หน้าทริป) ไม่มี
 * อะไรที่เป็นของ *การจอง* เลย — ไม่มีเลขที่จอง ไม่มีชื่อผู้เดินทาง ไม่มีจุดรับ
 * ไม่มียอดเงิน ไม่มีเบอร์โทร
 */
class TripStoryService
{
    /**
     * ข้อมูลการ์ดจากโทเคน คืน null เมื่อโทเคนไม่มีจริงหรือการจองถูกยกเลิกไปแล้ว
     *
     * @return array{trip_title: string, location: string, date_label: ?string, days_left: ?int, cover_url: ?string, trip_slug: ?string, headline: string, unit: ?string, kicker: string}|null
     */
    public function forToken(string $token): ?array
    {
        $booking = Booking::query()
            ->with('schedule.trip')
            ->where('story_token', mb_strtolower(trim($token)))
            ->first();

        // การจองที่ถูกยกเลิกต้องไม่เหลือหน้าค้างไว้ให้ลิงก์เก่ายังโชว์ทริปที่
        // เจ้าตัวไม่ได้ไปแล้ว
        if ($booking === null || $booking->status === 'cancelled') {
            return null;
        }

        $schedule = $booking->schedule;
        $trip = $schedule?->trip;

        if ($trip === null) {
            return null;
        }

        $daysLeft = $this->daysLeft($schedule->departure_date);
        [$headline, $unit, $kicker] = TripCountdownImageService::countdownParts($daysLeft);

        return [
            'trip_title' => (string) $trip->title,
            'location' => (string) ($trip->location ?? ''),
            'date_label' => $schedule->departure_date
                ? ThaiDate::full($schedule->departure_date)
                : null,
            'days_left' => $daysLeft,
            'cover_url' => MediaDisk::url($trip->cover_image ?: $trip->thumbnail_image),
            'trip_slug' => $trip->slug,
            'headline' => $headline,
            'unit' => $unit,
            'kicker' => $kicker,
        ];
    }

    /**
     * นับเป็น "จำนวนวัน" ตามปฏิทินไทยเสมอ
     *
     * departure_date เป็นคอลัมน์ date ที่เก็บวันตามเวลาไทย แต่ Eloquent cast ให้
     * เป็น Carbon ตาม timezone ของแอป (UTC) ถ้าเอาไป diff กับ now('Asia/Bangkok')
     * ตรง ๆ จะเหลือเศษ 7 ชั่วโมงติดมา แล้ว (int) ก็ตัดเศษเข้าหาศูนย์ ทำให้ทริปที่
     * ออกไปเมื่อวาน (-1) กลายเป็น 0 = "วันนี้!" ทั้งที่รถออกไปแล้ว
     *
     * จึงต้องปั้นทั้งสองฝั่งเป็นเที่ยงคืนตามเวลาไทยก่อน แล้วผลต่างจะเป็นจำนวนเต็มพอดี
     * (กับดักเดียวกับที่ TripActivityService เจอ — ดู reference_departs_at_timezone)
     */
    private function daysLeft(?CarbonInterface $departureDate): ?int
    {
        if ($departureDate === null) {
            return null;
        }

        $today = CarbonImmutable::now('Asia/Bangkok')->startOfDay();
        $departure = CarbonImmutable::parse($departureDate->toDateString(), 'Asia/Bangkok');

        return (int) round($today->diffInDays($departure, false));
    }
}
