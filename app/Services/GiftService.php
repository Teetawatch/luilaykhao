<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Support\Facades\DB;

/**
 * ซื้อทริปเป็นของขวัญ — ผู้ซื้อจองและชำระเงินเอง แล้วส่งโค้ดให้ผู้รับ
 * ผู้รับกดรับในแอป (ต้องล็อกอิน) แล้ว ownership ของการจองย้ายไปเป็นของผู้รับ
 */
class GiftService
{
    public function __construct(private MailService $mailService) {}

    /**
     * ข้อมูลของขวัญสำหรับหน้า preview ก่อนกดรับ — ไม่เปิดเผยราคาและเลขที่จอง
     * ให้ผู้รับเห็น (ของขวัญไม่ควรบอกราคา; เลขที่จองเปิดดูรายละเอียดเต็มได้)
     */
    public function preview(string $code, User $viewer): array
    {
        $booking = $this->findByCode($code);
        $booking->load('schedule.trip');

        $trip = $booking->schedule?->trip;
        $claimBlockedReason = $this->claimBlockedReason($booking, $viewer);

        return [
            'gift_code' => $booking->gift_code,
            'from_name' => $booking->gift_from_name,
            'message' => $booking->gift_message,
            'claimed' => $booking->gift_claimed_at !== null,
            'claimed_at' => $booking->gift_claimed_at?->toISOString(),
            'claimable' => $claimBlockedReason === null,
            'claim_blocked_reason' => $claimBlockedReason,
            'viewer_is_giver' => $booking->giftGiverUserId() === $viewer->id,
            'traveler_count' => $booking->passengers()->count(),
            'departure_date' => $booking->schedule?->departure_date?->toDateString(),
            'departure_label' => $booking->schedule?->departureLabelThai(),
            'trip' => $trip ? [
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'cover_image' => $trip->cover_image,
                'duration_days' => $trip->duration_days,
            ] : null,
        ];
    }

    /**
     * ข้อมูลของขวัญสำหรับหน้าเว็บสาธารณะ /gift/{code} — ไม่ต้องล็อกอิน
     * โชว์เพื่อ "เปิดของขวัญ" (ทริป ผู้ให้ คำอวยพร วันเดินทาง) โดยไม่มีราคา
     * คืน null เมื่อไม่พบโค้ด เพื่อให้ web controller ตอบ 404
     */
    public function publicView(string $code): ?array
    {
        $booking = Booking::where('gift_code', strtoupper(trim($code)))
            ->where('is_gift', true)
            ->with('schedule.trip')
            ->first();

        if (! $booking) {
            return null;
        }

        $trip = $booking->schedule?->trip;
        $cancelled = in_array($booking->status, ['cancelled', 'refunded'], true);

        return [
            'gift_code' => $booking->gift_code,
            'from_name' => $booking->gift_from_name,
            'message' => $booking->gift_message,
            'claimed' => $booking->gift_claimed_at !== null,
            'cancelled' => $cancelled,
            // พร้อมให้เปิดรับหรือยัง (ชำระครบ + ยังไม่ถูกรับ + รอบยังไม่ผ่าน)
            'ready' => ! $cancelled
                && $booking->gift_claimed_at === null
                && $booking->isFullyPaid()
                && $booking->schedule?->effectiveDepartsAt()?->isFuture() === true,
            'traveler_count' => $booking->passengers()->count(),
            'departure_label' => $booking->schedule?->departureLabelThai(),
            'trip_title' => $trip?->title,
            'trip_location' => $trip?->location,
            'trip_cover_image' => $trip?->cover_image
                ? MediaDisk::url($trip->cover_image)
                : null,
            'duration_days' => $trip?->duration_days,
        ];
    }

    /**
     * ผู้รับกดรับของขวัญ — ย้ายเจ้าของการจองจากผู้ซื้อไปเป็นผู้รับ และเติมข้อมูล
     * ผู้เดินทางคนแรกจากโปรไฟล์ของผู้รับ (ตอนซื้อผู้ให้กรอกแค่ชื่อเล่นผู้รับ)
     */
    public function claim(string $code, User $recipient): Booking
    {
        $claimed = DB::transaction(function () use ($code, $recipient) {
            $booking = Booking::where('gift_code', strtoupper(trim($code)))
                ->where('is_gift', true)
                ->lockForUpdate()
                ->first();

            if (! $booking) {
                throw new \Exception('ไม่พบโค้ดของขวัญนี้ กรุณาตรวจสอบอีกครั้ง');
            }

            $booking->load('schedule.trip');

            if ($reason = $this->claimBlockedReason($booking, $recipient)) {
                throw new \Exception($reason);
            }

            $booking->update([
                'gifted_by_user_id' => $booking->user_id,
                'user_id' => $recipient->id,
                'gift_claimed_at' => now(),
            ]);

            $this->fillFirstPassengerFromProfile($booking, $recipient);

            return $booking->fresh(['schedule.trip', 'passengers', 'giftedBy']);
        });

        // แจ้งผู้ให้ว่าของขวัญถูกเปิดรับแล้ว — นอก transaction, best-effort
        $trip = $claimed->schedule?->trip;
        $tripTitle = $trip?->title ?? 'ทริป';

        if ($claimed->gifted_by_user_id) {
            SmartNotification::send(
                $claimed->gifted_by_user_id,
                'gift_claimed',
                'ของขวัญถูกเปิดแล้ว 🎁',
                "{$recipient->name} กดรับของขวัญทริป \"{$tripTitle}\" ของคุณแล้ว",
                [
                    'booking_ref' => $claimed->booking_ref,
                    'route' => 'booking',
                ],
            );

            $this->mailService->sendGiftClaimedEmail($claimed, $recipient->name);
        }

        SmartNotification::send(
            $recipient->id,
            'gift_received',
            'รับของขวัญสำเร็จ 🎉',
            "ทริป \"{$tripTitle}\" เป็นของคุณแล้ว ดูรายละเอียดได้ที่การจองของฉัน",
            [
                'booking_ref' => $claimed->booking_ref,
                'route' => 'booking',
            ],
        );

        return $claimed;
    }

    /**
     * ของขวัญที่ผู้ใช้เป็นคนให้ — ก่อนรับ ผู้ซื้อยังเป็นเจ้าของ (user_id)
     * หลังรับ ผู้ซื้ออยู่ที่ gifted_by_user_id
     *
     * @return array<int, array<string, mixed>>
     */
    public function sentGifts(int $userId): array
    {
        return Booking::where('is_gift', true)
            ->where(function ($q) use ($userId) {
                $q->where(fn ($inner) => $inner->where('user_id', $userId)->whereNull('gift_claimed_at'))
                    ->orWhere('gifted_by_user_id', $userId);
            })
            ->with(['schedule.trip', 'user'])
            ->withCount('passengers')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Booking $booking) => [
                'booking_ref' => $booking->booking_ref,
                'gift_code' => $booking->gift_code,
                'from_name' => $booking->gift_from_name,
                'message' => $booking->gift_message,
                'status' => $booking->status,
                'is_fully_paid' => $booking->isFullyPaid(),
                'claimed' => $booking->gift_claimed_at !== null,
                'claimed_at' => $booking->gift_claimed_at?->toISOString(),
                // ชื่อผู้รับ มีความหมายเฉพาะหลังกดรับ (user กลายเป็นผู้รับแล้ว)
                'claimed_by_name' => $booking->gift_claimed_at !== null ? $booking->user?->name : null,
                'traveler_count' => (int) $booking->passengers_count,
                'departure_date' => $booking->schedule?->departure_date?->toDateString(),
                'departure_label' => $booking->schedule?->departureLabelThai(),
                'trip_title' => $booking->schedule?->trip?->title,
                'trip_cover_image' => $booking->schedule?->trip?->cover_image,
                'created_at' => $booking->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * เหตุผลที่ยังกดรับไม่ได้ — null คือรับได้เลย
     */
    private function claimBlockedReason(Booking $booking, User $recipient): ?string
    {
        if ($booking->gift_claimed_at !== null) {
            return 'ของขวัญนี้ถูกรับไปแล้ว';
        }

        if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
            return 'การจองของขวัญนี้ถูกยกเลิกแล้ว';
        }

        if ($booking->user_id === $recipient->id) {
            return 'ไม่สามารถรับของขวัญที่คุณเป็นผู้ซื้อเองได้';
        }

        if (! $booking->isFullyPaid()) {
            return 'ผู้ให้ยังชำระเงินไม่ครบ ของขวัญจะรับได้เมื่อชำระเงินเรียบร้อยแล้ว';
        }

        $departsAt = $booking->schedule?->effectiveDepartsAt();
        if ($departsAt === null || $departsAt->isPast()) {
            return 'รอบเดินทางของของขวัญนี้ผ่านไปแล้ว';
        }

        // ทริปผู้หญิงล้วน — กันเฉพาะกรณีโปรไฟล์ระบุคำนำหน้าเป็นชายชัดเจน
        if ($booking->schedule?->trip?->is_women_only && trim((string) $recipient->title) === 'นาย') {
            return 'ทริปนี้เป็นทริปสำหรับผู้หญิงเท่านั้น';
        }

        return null;
    }

    /**
     * เติมข้อมูลผู้เดินทางคนแรกจากโปรไฟล์ผู้รับ — เขียนทับเฉพาะช่องที่โปรไฟล์มีข้อมูล
     * (ผู้ให้อาจกรอกชื่อเล่นผู้รับไว้แล้ว ช่องที่โปรไฟล์ว่างให้คงของเดิม)
     */
    private function fillFirstPassengerFromProfile(Booking $booking, User $recipient): void
    {
        $passenger = $booking->passengers()->orderBy('id')->first();
        if (! $passenger) {
            return;
        }

        $fields = [
            'title' => $recipient->title,
            'name' => $recipient->name,
            'nickname' => $recipient->nickname,
            'phone' => $recipient->phone,
            'email' => $recipient->email,
            'id_card' => $recipient->id_card,
            'birth_date' => $recipient->birth_date,
            'blood_group' => $recipient->blood_group,
            'allergies' => $recipient->allergies,
            'health_notes' => $recipient->health_notes,
            'emergency_contact' => $recipient->emergency_contact,
            'emergency_phone' => $recipient->emergency_phone,
        ];

        $updates = array_filter($fields, fn ($value) => $value !== null && $value !== '');

        if ($updates !== []) {
            $passenger->update($updates);
        }
    }

    private function findByCode(string $code): Booking
    {
        $booking = Booking::where('gift_code', strtoupper(trim($code)))
            ->where('is_gift', true)
            ->first();

        if (! $booking) {
            throw new \Exception('ไม่พบโค้ดของขวัญนี้ กรุณาตรวจสอบอีกครั้ง');
        }

        return $booking;
    }
}
