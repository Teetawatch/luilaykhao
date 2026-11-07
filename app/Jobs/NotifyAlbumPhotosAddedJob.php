<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SchedulePhoto;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Services\AlbumPhotoNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "รูปทริปมาแล้ว" — ยิงหนึ่งครั้งต่อการอัปหนึ่งชุด ({@see AlbumPhotoNotifier})
 *
 * นับรูปตอนส่ง ไม่ใช่ตอนตั้งงาน: ชุดที่ยังอัปอยู่ตอนตั้งงานจะถูกนับครบ และรูปที่ถูก
 * ลบออกก่อนถึงเวลาส่งก็ไม่ถูกนับ (ลบหมดก็ไม่ส่ง)
 */
class NotifyAlbumPhotosAddedJob implements ShouldQueue
{
    use Queueable;

    public const TYPE = 'album_photos_added';

    /** สถานะใบจองที่ได้รับแจ้ง — คนที่ยังไม่จ่าย/ยกเลิกไปแล้วไม่ได้ไปทริปนี้ */
    private const BOOKING_STATUSES = ['confirmed', 'completed'];

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public readonly int $scheduleId,
        public readonly string $since,
    ) {}

    public function handle(AlbumPhotoNotifier $notifier): void
    {
        // ปล่อยคีย์ก่อนนับ — รูปที่เข้ามาหลังจากนี้จะตั้งงานชุดถัดไปเอง
        Cache::forget(AlbumPhotoNotifier::pendingKey($this->scheduleId));

        $schedule = TripSchedule::with('trip:id,title,slug')->find($this->scheduleId);
        if (! $schedule || ! $notifier->eligible($schedule)) {
            return;
        }

        // pivot เก็บเวลาตามเขตเวลาของแอป — เทียบในเขตเดียวกัน
        $since = CarbonImmutable::parse($this->since)->setTimezone(config('app.timezone'));

        $newCount = $schedule->photos()->wherePivot('created_at', '>=', $since)->count();
        if ($newCount === 0) {
            return;
        }
        $hadPhotos = $schedule->photos()->wherePivot('created_at', '<', $since)->exists();

        $recipients = $this->recipients($schedule);
        if ($recipients === []) {
            return;
        }

        Cache::put(
            AlbumPhotoNotifier::lastSentKey($schedule->id),
            now()->toIso8601String(),
            now()->addMinutes(AlbumPhotoNotifier::COOLDOWN_MINUTES),
        );

        $tripTitle = Str::limit((string) ($schedule->trip?->title ?? ''), 60);
        $tripPart = $tripTitle !== '' ? "ทริป{$tripTitle}" : 'ทริปของคุณ';
        $days = SchedulePhoto::RETENTION_DAYS;

        [$title, $body] = $hadPhotos
            ? ['📸 มีรูปทริปเพิ่มแล้ว', "ทีมงานเพิ่มรูป{$tripPart}อีก {$newCount} รูป แตะเพื่อดูและบันทึกเก็บไว้ รูปจะถูกลบอัตโนมัติภายใน {$days} วันหลังอัปโหลด"]
            : ['📸 รูปจากทริปมาแล้ว', "ทีมงานอัปรูป{$tripPart}ให้แล้ว {$newCount} รูป แตะเพื่อดูและบันทึกเก็บไว้ รูปจะถูกลบอัตโนมัติภายใน {$days} วันหลังอัปโหลด"];

        foreach ($recipients as $userId => $bookingRef) {
            SmartNotification::send($userId, self::TYPE, $title, $body, [
                'route' => 'album',
                'booking_ref' => $bookingRef,
                'schedule_id' => (string) $schedule->id,
                'photos_added' => (string) $newCount,
            ]);
        }
    }

    /**
     * เจ้าของใบจอง + เพื่อนที่เข้าร่วมใบจอง (ทั้งคู่เปิดรูปในใบจองได้) ไม่รวมสตาฟของรอบ
     * — สตาฟคือคนอัปเอง แต่ละคนได้แค่ครั้งเดียวแม้จะอยู่หลายใบจอง
     *
     * @return array<int, string> user id => booking ref ที่จะเปิดเมื่อแตะ
     */
    private function recipients(TripSchedule $schedule): array
    {
        $bookings = Booking::where('schedule_id', $schedule->id)
            ->whereIn('status', self::BOOKING_STATUSES)
            ->orderBy('id')
            ->get(['id', 'booking_ref', 'user_id']);

        $out = [];
        foreach ($bookings as $booking) {
            if ($booking->user_id && ! isset($out[$booking->user_id])) {
                $out[(int) $booking->user_id] = $booking->booking_ref;
            }
        }

        $refs = $bookings->pluck('booking_ref', 'id');
        BookingMember::where('status', BookingMember::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->whereIn('booking_id', $bookings->pluck('id'))
            ->orderBy('id')
            ->get(['booking_id', 'user_id'])
            ->each(function (BookingMember $member) use (&$out, $refs) {
                $out[(int) $member->user_id] ??= $refs[$member->booking_id];
            });

        foreach ($schedule->staff()->pluck('users.id') as $staffId) {
            unset($out[(int) $staffId]);
        }

        return $out;
    }
}
