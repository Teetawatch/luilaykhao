<?php

namespace App\Services;

use App\Jobs\VerifySlipJob;
use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingSplitShare;
use App\Models\SmartNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ระบบแบ่งจ่ายกลุ่ม (split payment) — แบ่งยอดคงเหลือของการจอง
 * ให้สมาชิกแต่ละคนชำระส่วนของตัวเองผ่านแอปหรือลิงก์เว็บสาธารณะ
 *
 * หลักการ: ผลรวมของส่วนแบ่งที่ยัง pending = booking.balance_amount เสมอ
 * เมื่อส่วนแบ่งถูกชำระ จะเพิ่ม paid_amount และหัก balance_amount ลง
 * เมื่อ balance_amount ถึงศูนย์ = ชำระครบ (ตั้ง balance_paid_at เหมือน flow ปกติ)
 */
class SplitPaymentService
{
    public function __construct(
        private MailService $mailService,
        private SmsService $smsService,
    ) {}

    /**
     * เริ่มแบ่งจ่าย: สร้างส่วนแบ่งจากยอดคงเหลือของการจองแบบมัดจำ
     * ถ้าไม่ส่ง $rows มา จะหารเท่ากันตามจำนวนผู้เดินทางอัตโนมัติ
     *
     * @param  list<array{passenger_id?: int|null, member_id?: int|null, label?: string|null, amount?: float|null}>|null  $rows
     * @return list<BookingSplitShare>
     */
    public function setup(Booking $booking, ?array $rows = null): array
    {
        if ($booking->status !== 'confirmed') {
            throw new \Exception('การจองต้องได้รับการยืนยันก่อนจึงจะแบ่งจ่ายได้');
        }

        if ($booking->payment_type !== 'deposit' || $booking->balance_paid_at !== null) {
            throw new \Exception('การจองนี้ไม่มียอดคงเหลือให้แบ่งจ่าย');
        }

        $outstanding = (float) $booking->balance_amount;

        if ($outstanding <= 0) {
            throw new \Exception('การจองนี้ไม่มียอดคงเหลือให้แบ่งจ่าย');
        }

        if ($booking->splitShares()->exists()) {
            throw new \Exception('การจองนี้เริ่มแบ่งจ่ายไปแล้ว');
        }

        $rows = $rows !== null && $rows !== []
            ? $this->validateRows($booking, $rows, $outstanding)
            : $this->equalRows($booking, $outstanding);

        $shares = DB::transaction(function () use ($booking, $rows) {
            $created = [];
            foreach ($rows as $row) {
                $created[] = BookingSplitShare::create([
                    'booking_id' => $booking->id,
                    'member_id' => $row['member_id'],
                    'passenger_id' => $row['passenger_id'],
                    'label' => $row['label'],
                    'amount' => $row['amount'],
                    'status' => BookingSplitShare::STATUS_PENDING,
                    'pay_token' => BookingSplitShare::generateToken(),
                ]);
            }

            return $created;
        });

        $this->notifySharesCreated($booking, $shares);

        return $shares;
    }

    /**
     * สร้างส่วนแบ่งตอน "จ่ายเต็มแบบแบ่งจ่าย" — เจ้าของชำระส่วนตัวเองแล้ว
     * ยอดที่เหลือถูกหารให้ผู้เดินทางคนอื่น เรียกภายใน transaction ของ charge()
     *
     * @return list<BookingSplitShare>
     */
    public function createSharesForRemainder(Booking $booking, float $remainder, int $shareCount): array
    {
        $amounts = $this->divideEvenly($remainder, $shareCount);

        // ผูกส่วนแบ่งกับผู้เดินทางคนอื่นที่ไม่ใช่เจ้าของ (ถ้ามีข้อมูล)
        $passengers = $booking->passengers()->orderBy('id')->get()->slice(1)->values();

        $created = [];
        foreach ($amounts as $i => $amount) {
            $passenger = $passengers->get($i);
            $created[] = BookingSplitShare::create([
                'booking_id' => $booking->id,
                'member_id' => null,
                'passenger_id' => $passenger?->id,
                'label' => $passenger ? null : 'ผู้ร่วมทริป '.($i + 1),
                'amount' => $amount,
                'status' => BookingSplitShare::STATUS_PENDING,
                'pay_token' => BookingSplitShare::generateToken(),
            ]);
        }

        return $created;
    }

    /**
     * เจ้าของแก้ยอด/ผู้รับผิดชอบของส่วนแบ่งที่ยังไม่ถูกชำระ
     * ส่ง rows ครบชุดของส่วนที่ยัง pending: มี id = แก้ไข, ไม่มี id = เพิ่มใหม่,
     * id เดิมที่หายไป = ลบทิ้ง — ผลรวมต้องเท่ายอดคงเหลือพอดี
     *
     * @param  list<array{id?: int|null, passenger_id?: int|null, member_id?: int|null, label?: string|null, amount: float}>  $rows
     * @return list<BookingSplitShare>
     */
    public function updateShares(Booking $booking, array $rows): array
    {
        $outstanding = (float) $booking->balance_amount;

        if ($outstanding <= 0 || $booking->balance_paid_at !== null) {
            throw new \Exception('การจองนี้ชำระครบแล้ว');
        }

        $rows = $this->validateRows($booking, $rows, $outstanding);

        return DB::transaction(function () use ($booking, $rows) {
            $pending = $booking->splitShares()
                ->where('status', BookingSplitShare::STATUS_PENDING)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $keptIds = [];
            $result = [];

            foreach ($rows as $row) {
                $id = $row['id'] ?? null;

                if ($id !== null) {
                    $share = $pending->get($id);
                    if (! $share) {
                        throw new \Exception('ไม่พบส่วนแบ่งที่ต้องการแก้ไข หรือส่วนนั้นถูกชำระไปแล้ว');
                    }

                    $share->update([
                        'member_id' => $row['member_id'],
                        'passenger_id' => $row['passenger_id'],
                        'label' => $row['label'],
                        'amount' => $row['amount'],
                    ]);
                    $keptIds[] = $id;
                    $result[] = $share->fresh();
                } else {
                    $result[] = BookingSplitShare::create([
                        'booking_id' => $booking->id,
                        'member_id' => $row['member_id'],
                        'passenger_id' => $row['passenger_id'],
                        'label' => $row['label'],
                        'amount' => $row['amount'],
                        'status' => BookingSplitShare::STATUS_PENDING,
                        'pay_token' => BookingSplitShare::generateToken(),
                    ]);
                }
            }

            // ลบส่วน pending เดิมที่ไม่อยู่ในชุดใหม่
            $pending->except($keptIds)->each->delete();

            return $result;
        });
    }

    /**
     * ยกเลิกการแบ่งจ่าย — ลบเฉพาะส่วนที่ยังไม่ถูกชำระ ส่วนที่จ่ายแล้วคงไว้เป็นประวัติ
     * ยอดที่เหลือกลับไปใช้ช่องทางชำระยอดคงเหลือปกติ
     */
    public function cancel(Booking $booking): void
    {
        $booking->splitShares()
            ->where('status', BookingSplitShare::STATUS_PENDING)
            ->delete();
    }

    /**
     * บันทึกการชำระส่วนแบ่งหนึ่งส่วน (จากแอปหรือลิงก์เว็บ)
     */
    public function payShare(
        BookingSplitShare $share,
        string $paymentMethod,
        ?string $slipPath = null,
        ?string $transferDt = null,
    ): BookingSplitShare {
        $paymentRef = 'PAY-SHARE-'.strtoupper(uniqid());

        [$share, $booking, $allPaid] = DB::transaction(function () use ($share, $paymentMethod, $paymentRef, $slipPath, $transferDt) {
            $locked = BookingSplitShare::whereKey($share->id)->lockForUpdate()->first();

            if (! $locked || $locked->isPaid()) {
                throw new \Exception('ส่วนแบ่งนี้ถูกชำระไปแล้ว');
            }

            $booking = Booking::whereKey($locked->booking_id)->lockForUpdate()->first();

            $locked->update([
                'status' => BookingSplitShare::STATUS_PAID,
                'payment_method' => $paymentMethod,
                'payment_ref' => $paymentRef,
                'slip_path' => $slipPath,
                'transfer_datetime' => $transferDt,
                'slip_ocr_status' => $slipPath ? SlipOcrService::STATUS_PENDING : null,
                'paid_at' => now(),
            ]);

            $amount = (float) $locked->amount;
            $newBalance = round(max(0, (float) $booking->balance_amount - $amount), 2);
            $allPaid = $newBalance <= 0;

            $booking->update([
                'paid_amount' => round((float) $booking->paid_amount + $amount, 2),
                'balance_amount' => $newBalance,
                'balance_paid_at' => $allPaid ? now() : null,
                'balance_payment_ref' => $allPaid ? $paymentRef : $booking->balance_payment_ref,
            ]);

            return [$locked->fresh(), $booking->fresh(), $allPaid];
        });

        if ($slipPath) {
            VerifySlipJob::dispatch('split', $share->id, $slipPath, (float) $share->amount);
        }

        $this->notifySharePaid($booking, $share, $allPaid);

        if ($allPaid) {
            $fresh = $booking->load(['seats', 'schedule.trip', 'passengers']);

            try {
                $this->mailService->sendBalancePaidEmail($fresh);
                $this->smsService->sendBalancePaid($fresh);
            } catch (\Throwable $e) {
                Log::warning('Split payment completion notification failed: '.$e->getMessage());
            }
        }

        return $share;
    }

    /**
     * เจ้าของกดเตือนสมาชิกที่ยังไม่จ่าย — ส่ง push ซ้ำได้ทุก 1 ชั่วโมง
     */
    public function remind(Booking $booking, int $shareId): BookingSplitShare
    {
        $share = $booking->splitShares()->whereKey($shareId)->first();

        if (! $share || $share->isPaid()) {
            throw new \Exception('ไม่พบส่วนแบ่งที่ยังค้างชำระ');
        }

        if ($share->reminded_at !== null && $share->reminded_at->gt(now()->subHour())) {
            throw new \Exception('เพิ่งส่งเตือนไปเมื่อไม่นานนี้ กรุณารอสักครู่');
        }

        $userId = $share->member?->user_id;

        if ($userId === null) {
            throw new \Exception('สมาชิกคนนี้ยังไม่ได้เข้าร่วมผ่านแอป กรุณาส่งลิงก์ชำระเงินให้โดยตรง');
        }

        $tripName = $booking->schedule?->trip?->title ?? $booking->booking_ref;
        $amountText = number_format((float) $share->amount, 2);

        SmartNotification::send(
            $userId,
            'split_share_reminder',
            'เตือนชำระส่วนของคุณ',
            "อย่าลืมชำระส่วนของคุณ {$amountText} บาท สำหรับทริป {$tripName}",
            [
                'booking_ref' => $booking->booking_ref,
                'share_id' => $share->id,
                'route' => 'split_share',
            ],
        );

        $share->update(['reminded_at' => now()]);

        return $share->fresh();
    }

    /**
     * ข้อมูลภาพรวมการแบ่งจ่ายสำหรับ API — รายการส่วนแบ่ง + สถานะ + ลิงก์จ่าย
     */
    public function overview(Booking $booking, ?int $viewerUserId = null): array
    {
        $shares = $booking->splitShares()
            ->with(['member.user:id,name,nickname,avatar', 'passenger:id,name,nickname'])
            ->get();

        $isOwner = $viewerUserId !== null && $booking->user_id === $viewerUserId;

        return [
            'enabled' => $shares->isNotEmpty(),
            'total_shares' => $shares->count(),
            'paid_shares' => $shares->where('status', BookingSplitShare::STATUS_PAID)->count(),
            'outstanding_amount' => (float) $booking->balance_amount,
            'balance_due_at' => $booking->balance_due_at?->toISOString(),
            'is_owner' => $isOwner,
            'shares' => $shares->map(function (BookingSplitShare $s) use ($isOwner, $viewerUserId) {
                $isMine = $viewerUserId !== null && $s->member?->user_id === $viewerUserId;

                return [
                    'id' => $s->id,
                    'name' => $s->displayName(),
                    'amount' => (float) $s->amount,
                    'status' => $s->status,
                    'paid_at' => $s->paid_at?->toISOString(),
                    'member_id' => $s->member_id,
                    'passenger_id' => $s->passenger_id,
                    'label' => $s->label,
                    'is_mine' => $isMine,
                    'avatar_url' => $s->member?->user?->avatar_url,
                    'reminded_at' => $s->reminded_at?->toISOString(),
                    // ลิงก์จ่ายเป็นความลับ — เห็นเฉพาะเจ้าของ หรือเจ้าของส่วนแบ่งเอง
                    'pay_url' => ($isOwner || $isMine) ? $s->payUrl() : null,
                ];
            })->values()->all(),
        ];
    }

    /**
     * ผูกสมาชิกที่เพิ่งรับคำเชิญเข้ากับส่วนแบ่งที่ยังไม่มีเจ้าของ
     * (จับคู่จาก passenger เดียวกันก่อน ไม่มีก็หยิบส่วนว่างส่วนแรก)
     * แล้วแจ้ง push บอกยอดที่ต้องจ่าย — เรียกหลัง acceptInvite สำเร็จ
     */
    public function linkMemberToShare(BookingMember $member): void
    {
        $booking = $member->booking()->with('schedule.trip')->first();

        if (! $booking) {
            return;
        }

        $query = $booking->splitShares()
            ->where('status', BookingSplitShare::STATUS_PENDING)
            ->whereNull('member_id');

        $share = $member->passenger_id !== null
            ? (clone $query)->where('passenger_id', $member->passenger_id)->first()
            : null;

        $share ??= $query->whereNull('passenger_id')->orderBy('id')->first();

        if (! $share) {
            return;
        }

        $share->update(['member_id' => $member->id]);

        if ($member->user_id !== null) {
            $tripName = $booking->schedule?->trip?->title ?? $booking->booking_ref;
            $amountText = number_format((float) $share->amount, 2);

            SmartNotification::send(
                $member->user_id,
                'split_share_created',
                'ถึงตาคุณช่วยจ่ายแล้ว',
                "ทริป {$tripName} — ส่วนของคุณ {$amountText} บาท กดเพื่อชำระผ่านแอปได้เลย",
                [
                    'booking_ref' => $booking->booking_ref,
                    'share_id' => $share->id,
                    'route' => 'split_share',
                ],
            );
        }
    }

    /**
     * แจ้ง push ให้สมาชิกที่ผูกบัญชีแอปแล้ว เมื่อเจ้าของเริ่มแบ่งจ่าย
     *
     * @param  list<BookingSplitShare>  $shares
     */
    public function notifySharesCreated(Booking $booking, array $shares): void
    {
        $tripName = $booking->schedule?->trip?->title ?? $booking->booking_ref;

        foreach ($shares as $share) {
            $userId = $share->member?->user_id;

            if ($userId === null || $userId === $booking->user_id) {
                continue;
            }

            $amountText = number_format((float) $share->amount, 2);

            SmartNotification::send(
                $userId,
                'split_share_created',
                'ถึงตาคุณช่วยจ่ายแล้ว',
                "ทริป {$tripName} — ส่วนของคุณ {$amountText} บาท กดเพื่อชำระผ่านแอปได้เลย",
                [
                    'booking_ref' => $booking->booking_ref,
                    'share_id' => $share->id,
                    'route' => 'split_share',
                ],
            );
        }
    }

    /**
     * แจ้งเจ้าของเมื่อมีคนจ่ายส่วนของตัวเอง และแจ้งทุกคนเมื่อครบทั้งกลุ่ม
     */
    private function notifySharePaid(Booking $booking, BookingSplitShare $share, bool $allPaid): void
    {
        $name = $share->displayName();
        $amountText = number_format((float) $share->amount, 2);
        $tripName = $booking->schedule?->trip?->title ?? $booking->booking_ref;

        if ($booking->user_id) {
            $remaining = $booking->splitShares()
                ->where('status', BookingSplitShare::STATUS_PENDING)
                ->count();

            $body = $allPaid
                ? "{$name} ชำระ {$amountText} บาทแล้ว — ทริป {$tripName} ชำระครบทั้งกลุ่มแล้ว 🎉"
                : "{$name} ชำระ {$amountText} บาทแล้ว เหลืออีก {$remaining} คน";

            SmartNotification::send(
                $booking->user_id,
                $allPaid ? 'split_all_paid' : 'split_share_paid',
                $allPaid ? 'ชำระครบทั้งกลุ่มแล้ว' : 'มีคนจ่ายส่วนของตัวเองแล้ว',
                $body,
                [
                    'booking_ref' => $booking->booking_ref,
                    'route' => 'booking',
                ],
            );
        }

        // ยืนยันกับคนจ่าย (ถ้าจ่ายผ่านบัญชีแอป)
        $payerId = $share->member?->user_id;
        if ($payerId !== null && $payerId !== $booking->user_id) {
            SmartNotification::send(
                $payerId,
                'split_share_receipt',
                'รับชำระส่วนของคุณแล้ว',
                "รับชำระ {$amountText} บาท สำหรับทริป {$tripName} เรียบร้อยแล้ว",
                [
                    'booking_ref' => $booking->booking_ref,
                    'route' => 'booking',
                ],
            );
        }

        // ครบทั้งกลุ่ม — แจ้งสมาชิกแอปทุกคน (ยกเว้นเจ้าของที่ได้รับข้างบนแล้ว)
        if ($allPaid) {
            $memberUserIds = $booking->members()
                ->where('status', BookingMember::STATUS_ACTIVE)
                ->whereNotNull('user_id')
                ->pluck('user_id');

            foreach ($memberUserIds as $userId) {
                if ($userId === $booking->user_id) {
                    continue;
                }

                SmartNotification::send(
                    $userId,
                    'split_all_paid',
                    'ทริปนี้ชำระครบแล้ว',
                    "ทริป {$tripName} ชำระครบทั้งกลุ่มแล้ว เตรียมตัวออกเดินทางได้เลย 🎉",
                    [
                        'booking_ref' => $booking->booking_ref,
                        'route' => 'booking',
                    ],
                );
            }
        }
    }

    /**
     * หารยอดคงเหลือเท่ากันตามจำนวนผู้เดินทาง แล้วผูกกับ passenger/สมาชิกแอป
     *
     * @return list<array{member_id: int|null, passenger_id: int|null, label: string|null, amount: float}>
     */
    private function equalRows(Booking $booking, float $outstanding): array
    {
        $passengers = $booking->passengers()->orderBy('id')->get();
        $count = max(1, $passengers->count());
        $amounts = $this->divideEvenly($outstanding, $count);

        $membersByPassenger = $booking->members()
            ->whereIn('status', [BookingMember::STATUS_PENDING, BookingMember::STATUS_ACTIVE])
            ->whereNotNull('passenger_id')
            ->get()
            ->keyBy('passenger_id');

        $rows = [];
        foreach ($passengers as $i => $passenger) {
            $rows[] = [
                'member_id' => $membersByPassenger->get($passenger->id)?->id,
                'passenger_id' => $passenger->id,
                'label' => null,
                'amount' => $amounts[$i],
            ];
        }

        return $rows;
    }

    /**
     * ตรวจ rows ที่เจ้าของกำหนดเอง: ยอดบวก, ผลรวมตรงยอดคงเหลือ,
     * passenger/member ต้องเป็นของการจองนี้จริง
     *
     * @return list<array<string, mixed>>
     */
    private function validateRows(Booking $booking, array $rows, float $outstanding): array
    {
        if ($rows === []) {
            throw new \Exception('ต้องมีส่วนแบ่งอย่างน้อย 1 ส่วน');
        }

        $passengerIds = $booking->passengers()->pluck('id')->all();
        $memberIds = $booking->members()->pluck('id')->all();

        $sum = 0.0;
        $normalized = [];

        foreach ($rows as $row) {
            $amount = round((float) ($row['amount'] ?? 0), 2);

            if ($amount <= 0) {
                throw new \Exception('ยอดของแต่ละส่วนต้องมากกว่า 0 บาท');
            }

            $passengerId = isset($row['passenger_id']) ? (int) $row['passenger_id'] : null;
            $memberId = isset($row['member_id']) ? (int) $row['member_id'] : null;

            if ($passengerId !== null && ! in_array($passengerId, $passengerIds, true)) {
                throw new \Exception('ผู้เดินทางบางรายไม่อยู่ในการจองนี้');
            }

            if ($memberId !== null && ! in_array($memberId, $memberIds, true)) {
                throw new \Exception('สมาชิกบางรายไม่อยู่ในการจองนี้');
            }

            $label = isset($row['label']) ? trim((string) $row['label']) : null;

            $sum = round($sum + $amount, 2);
            $normalized[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'member_id' => $memberId,
                'passenger_id' => $passengerId,
                'label' => $label !== '' ? $label : null,
                'amount' => $amount,
            ];
        }

        if (abs($sum - $outstanding) > 0.01) {
            $outstandingText = number_format($outstanding, 2);

            throw new \Exception("ผลรวมของทุกส่วนต้องเท่ากับยอดคงเหลือ {$outstandingText} บาทพอดี");
        }

        return $normalized;
    }

    /**
     * หารยอดเท่า ๆ กัน โดยงวดสุดท้ายรับเศษปัดเพื่อให้ผลรวมตรงเป๊ะ
     *
     * @return list<float>
     */
    private function divideEvenly(float $total, int $count): array
    {
        $per = round($total / $count, 2);
        $amounts = array_fill(0, $count, $per);
        $amounts[$count - 1] = round($total - ($per * ($count - 1)), 2);

        return $amounts;
    }
}
