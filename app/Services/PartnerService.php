<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Models\SmartNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * พาร์ทเนอร์ช่วยขาย — คนนอกแชร์ลิงก์/โค้ดของตัวเอง แล้วได้ค่าคอมจากใบจองที่เดินทางจริง
 *
 * กติกาเงิน:
 * - ลูกค้าจ่ายเข้าบริษัทตรงทางเดิมเสมอ พาร์ทเนอร์ไม่เคยแตะเงินลูกค้า
 * - ผูกกับใบจอง ไม่ใช่ตัวลูกค้า · ใบที่ผูกแล้วไม่ถูกลิงก์ใหม่ทับ (ทีมงานเปลี่ยนได้)
 * - จองให้ตัวเองไม่ได้คอม · พาร์ทเนอร์ที่หยุดไว้ไม่รับใบใหม่
 * - คอมเกิด (pending) เมื่อใบจองยืนยัน แช่อัตรา ณ ตอนนั้นไว้ · อนุมัติหลังวันกลับของรอบ
 *   และชำระครบแล้ว โดยคิดยอดใหม่จากใบจอง ณ วันนั้น (ย้ายรอบ/ลดคน/ปรับยอดก็ตามมาเอง)
 * - ฐานคอม = ค่าทริปหลังส่วนลด ไม่รวมค่าบริการเสริม ค่าเช่าอุปกรณ์ และยอดที่ยกเว้น
 *   (บัตรของขวัญนับ เพราะเป็นเงินที่ลูกค้าจ่ายมาแล้ว)
 * - ยกเลิก/คืนเงินก่อนจ่ายคอม → ตัดทิ้ง · จ่ายไปแล้ว → แถว clawback ติดลบ หักรอบถัดไป
 */
class PartnerService
{
    /** สถานะใบจองที่ถือว่าขายได้จริง */
    public const EARNING_STATUSES = ['confirmed', 'completed'];

    public const ENDED_STATUSES = ['cancelled', 'refunded'];

    public const SOURCES = ['link', 'code', 'admin'];

    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // ── บัญชีพาร์ทเนอร์ ─────────────────────────────────────────────

    public function create(User $admin, array $data): Partner
    {
        $user = User::findOrFail($data['user_id']);

        if (Partner::where('user_id', $user->id)->exists()) {
            throw new \Exception('บัญชีนี้เป็นพาร์ทเนอร์อยู่แล้ว');
        }

        $code = filled($data['code'] ?? null) ? Partner::normalizeCode($data['code']) : $this->generateCode();
        $this->assertCodeAvailable($code);

        return Partner::create([
            'user_id' => $user->id,
            'code' => $code,
            'display_name' => $data['display_name'] ?? $user->name,
            'commission_type' => $data['commission_type'],
            'commission_rate' => $data['commission_rate'],
            'status' => Partner::STATUS_ACTIVE,
            'payout_account' => $this->cleanAccount($data['payout_account'] ?? null),
            'note' => $data['note'] ?? null,
            'created_by_id' => $admin->id,
        ]);
    }

    public function update(Partner $partner, array $data): Partner
    {
        if (array_key_exists('code', $data) && filled($data['code'])) {
            $code = Partner::normalizeCode($data['code']);
            if ($code !== $partner->code) {
                $this->assertCodeAvailable($code);
                $partner->code = $code;
            }
        }

        foreach (['display_name', 'commission_type', 'commission_rate', 'status', 'note'] as $field) {
            if (array_key_exists($field, $data)) {
                $partner->{$field} = $data[$field];
            }
        }

        if (array_key_exists('payout_account', $data)) {
            $partner->payout_account = $this->cleanAccount($data['payout_account']);
        }

        $partner->save();

        return $partner;
    }

    /** พาร์ทเนอร์แก้บัญชีรับเงินของตัวเอง */
    public function updatePayoutAccount(Partner $partner, array $account): Partner
    {
        $partner->update(['payout_account' => $this->cleanAccount($account)]);

        return $partner;
    }

    public function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Partner::where('code', $code)->exists());

        return $code;
    }

    private function assertCodeAvailable(string $code): void
    {
        if (strlen($code) < 4 || strlen($code) > 20) {
            throw new \Exception('โค้ดพาร์ทเนอร์ต้องเป็นตัวอักษรอังกฤษหรือตัวเลข 4–20 ตัว');
        }

        if (Partner::where('code', $code)->exists()) {
            throw new \Exception('โค้ด '.$code.' ถูกใช้แล้ว');
        }
    }

    private function cleanAccount(?array $account): ?array
    {
        if (! $account || blank($account['number'] ?? null)) {
            return null;
        }

        return [
            'bank' => trim((string) ($account['bank'] ?? '')),
            'number' => trim((string) $account['number']),
            'name' => trim((string) ($account['name'] ?? '')),
        ];
    }

    // ── ผูกกับใบจอง ─────────────────────────────────────────────────

    /**
     * ผูกใบจองที่ลูกค้าเพิ่งสร้างกับโค้ดที่แนบมา (จากลิงก์หรือพิมพ์เอง)
     *
     * ไม่เคยทำให้การจองล้ม — โค้ดผิด/หยุดใช้/จองให้ตัวเอง แค่ไม่ผูก
     */
    public function attributeFromCode(Booking $booking, ?string $code, string $source = 'link'): bool
    {
        if ($booking->partner_id || blank($code)) {
            return false;
        }

        $partner = Partner::findByCode($code);

        if (! $partner || ! $partner->isActive() || $partner->user_id === $booking->user_id) {
            return false;
        }

        $booking->forceFill([
            'partner_id' => $partner->id,
            'partner_source' => in_array($source, self::SOURCES, true) ? $source : 'link',
        ])->saveQuietly();

        // บัตรของขวัญจ่ายครบ = ใบยืนยันไปแล้วก่อนมาถึงตรงนี้ ไม่มีการเปลี่ยนสถานะให้จับอีก
        $this->ensureCommission($booking);

        return true;
    }

    /**
     * ทีมงานผูก/เปลี่ยน/ถอดพาร์ทเนอร์ของใบจอง (ลูกค้าทักมาบอกว่ามาจากใคร)
     */
    public function assignByAdmin(Booking $booking, ?Partner $partner): Booking
    {
        return DB::transaction(function () use ($booking, $partner) {
            $booking = Booking::lockForUpdate()->findOrFail($booking->id);

            if ($partner && $partner->user_id === $booking->user_id) {
                throw new \Exception('ใบจองนี้เป็นของพาร์ทเนอร์เอง ผูกคอมให้ตัวเองไม่ได้');
            }

            if ($partner?->id === $booking->partner_id) {
                return $booking;
            }

            $current = PartnerCommission::where('booking_id', $booking->id)
                ->where('type', PartnerCommission::TYPE_COMMISSION)
                ->lockForUpdate()
                ->first();

            if ($current && $current->status === PartnerCommission::STATUS_PAID) {
                throw new \Exception('จ่ายคอมของใบนี้ให้พาร์ทเนอร์เดิมไปแล้ว เปลี่ยนไม่ได้');
            }

            // แถวเดิมยังไม่มีใครได้เงิน ลบทิ้งเลยเพื่อให้ unique(booking_id, type) ว่างให้คนใหม่
            $current?->delete();

            $booking->forceFill([
                'partner_id' => $partner?->id,
                'partner_source' => $partner ? 'admin' : null,
            ])->saveQuietly();

            $this->ensureCommission($booking, notify: false);

            return $booking;
        });
    }

    // ── วงจรค่าคอม ──────────────────────────────────────────────────

    /**
     * ใบจองยืนยันแล้ว → เปิดแถวคอม (หรือชุบแถวที่เคยถูกตัดเพราะยกเลิกขึ้นมาใหม่)
     */
    public function ensureCommission(Booking $booking, bool $notify = true): ?PartnerCommission
    {
        if (! $booking->partner_id || ! in_array($booking->status, self::EARNING_STATUSES, true)) {
            return null;
        }

        $partner = Partner::find($booking->partner_id);

        if (! $partner) {
            return null;
        }

        $existing = PartnerCommission::where('booking_id', $booking->id)
            ->where('type', PartnerCommission::TYPE_COMMISSION)
            ->first();

        if ($existing && $existing->status !== PartnerCommission::STATUS_VOID) {
            return $existing;
        }

        $snapshot = $this->snapshot($booking, $partner->commission_type, (float) $partner->commission_rate);

        if ($existing) {
            $existing->update($snapshot + [
                'status' => PartnerCommission::STATUS_PENDING,
                'voided_at' => null,
                'void_reason' => null,
                'approved_at' => null,
            ]);

            return $existing;
        }

        $commission = PartnerCommission::create($snapshot + [
            'partner_id' => $partner->id,
            'booking_id' => $booking->id,
            'type' => PartnerCommission::TYPE_COMMISSION,
            'status' => PartnerCommission::STATUS_PENDING,
            'rate_type' => $partner->commission_type,
            'rate' => $partner->commission_rate,
        ]);

        // ใบที่แอดมินแยกออกมาตอนย้ายรอบ (replicate พา partner_id มาด้วย) ถูกสร้างก่อน
        // ย้ายผู้เดินทางเข้าไป — ไม่ใช่ยอดขายใหม่ ไม่ต้องแจ้ง (ยอดจริงคิดใหม่ตอนอนุมัติ)
        if ($notify && $commission->seats > 0) {
            $this->notifySale($partner, $commission);
        }

        return $commission;
    }

    /**
     * ใบจองถูกยกเลิก/คืนเงิน — ยังไม่จ่ายก็ตัดทิ้ง จ่ายไปแล้วก็ตั้งยอดหักคืน
     */
    public function handleBookingEnded(Booking $booking): void
    {
        $commission = PartnerCommission::where('booking_id', $booking->id)
            ->where('type', PartnerCommission::TYPE_COMMISSION)
            ->first();

        if (! $commission) {
            return;
        }

        $reason = $booking->status === 'refunded' ? 'คืนเงินการจอง' : 'ยกเลิกการจอง';

        if (in_array($commission->status, [PartnerCommission::STATUS_PENDING, PartnerCommission::STATUS_APPROVED], true)) {
            $commission->update([
                'status' => PartnerCommission::STATUS_VOID,
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return;
        }

        if ($commission->status !== PartnerCommission::STATUS_PAID || (float) $commission->amount <= 0) {
            return;
        }

        PartnerCommission::firstOrCreate(
            ['booking_id' => $booking->id, 'type' => PartnerCommission::TYPE_CLAWBACK],
            [
                'partner_id' => $commission->partner_id,
                'booking_ref' => $commission->booking_ref,
                'trip_title' => $commission->trip_title,
                'departure_date' => $commission->departure_date,
                'status' => PartnerCommission::STATUS_APPROVED,
                'seats' => 0,
                'base_amount' => 0,
                'rate_type' => $commission->rate_type,
                'rate' => $commission->rate,
                'amount' => -1 * (float) $commission->amount,
                'approved_at' => now(),
                'void_reason' => $reason.' หลังจ่ายคอมแล้ว',
            ],
        );
    }

    /**
     * อนุมัติคอมของรอบที่กลับมาแล้ว (วันกลับผ่านไปแล้วอย่างน้อยหนึ่งวัน) และชำระครบ
     *
     * ใบที่ยังค้างชำระรออยู่ในสถานะเดิมจนกว่าจะจ่ายครบ ไม่ตัดทิ้ง
     */
    public function approveDue(?Carbon $today = null): int
    {
        $today = ($today ?? Carbon::now('Asia/Bangkok'))->copy()->startOfDay();
        $approved = 0;

        PartnerCommission::where('type', PartnerCommission::TYPE_COMMISSION)
            ->where('status', PartnerCommission::STATUS_PENDING)
            ->whereNotNull('booking_id')
            ->with(['booking.schedule.trip', 'partner'])
            ->chunkById(200, function ($rows) use ($today, &$approved) {
                foreach ($rows as $commission) {
                    $booking = $commission->booking;
                    $schedule = $booking?->schedule;

                    if (! $booking || ! $schedule || $booking->awaitsNewRound()) {
                        continue;
                    }

                    if (! in_array($booking->status, self::EARNING_STATUSES, true)) {
                        continue;
                    }

                    $endDate = ($schedule->return_date ?? $schedule->departure_date)?->toDateString();

                    if (! $endDate || Carbon::parse($endDate, 'Asia/Bangkok')->startOfDay()->gte($today)) {
                        continue;
                    }

                    if ($booking->status === 'confirmed' && ! $booking->isFullyPaid()) {
                        continue;
                    }

                    $commission->update($this->snapshot($booking, $commission->rate_type, (float) $commission->rate) + [
                        'status' => PartnerCommission::STATUS_APPROVED,
                        'approved_at' => now(),
                    ]);
                    $approved++;
                }
            });

        return $approved;
    }

    /** ทีมงานตัดคอมทิ้งเอง (เช่น พาร์ทเนอร์ให้คนในบ้านจองเพื่อเอาคอม) */
    public function voidCommission(PartnerCommission $commission, string $reason): PartnerCommission
    {
        if (! in_array($commission->status, [PartnerCommission::STATUS_PENDING, PartnerCommission::STATUS_APPROVED], true)) {
            throw new \Exception('ตัดได้เฉพาะคอมที่ยังไม่ได้จ่าย');
        }

        $commission->update([
            'status' => PartnerCommission::STATUS_VOID,
            'voided_at' => now(),
            'void_reason' => $reason,
        ]);

        return $commission;
    }

    /**
     * บันทึกการโอนคอม — กวาดทุกแถวที่อนุมัติแล้ว (รวมยอดหักคืน) เข้าก้อนเดียว
     */
    public function recordPayout(Partner $partner, User $admin, array $data): PartnerPayout
    {
        $payout = DB::transaction(function () use ($partner, $admin, $data) {
            $rows = PartnerCommission::where('partner_id', $partner->id)
                ->where('status', PartnerCommission::STATUS_APPROVED)
                ->whereNull('payout_id')
                ->lockForUpdate()
                ->get();

            $total = round((float) $rows->sum('amount'), 2);

            if ($rows->isEmpty() || $total <= 0) {
                throw new \Exception('ยังไม่มียอดคอมที่อนุมัติแล้วให้จ่าย');
            }

            $payout = PartnerPayout::create([
                'partner_id' => $partner->id,
                'amount' => $total,
                'commission_count' => $rows->where('type', PartnerCommission::TYPE_COMMISSION)->count(),
                'paid_at' => now(),
                'reference' => $data['reference'] ?? null,
                'slip_path' => $data['slip_path'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_by_id' => $admin->id,
            ]);

            PartnerCommission::whereIn('id', $rows->pluck('id'))->update([
                'status' => PartnerCommission::STATUS_PAID,
                'payout_id' => $payout->id,
            ]);

            return $payout;
        });

        $this->safeNotify(
            $partner->user_id,
            'partner_payout',
            'โอนค่าคอมแล้ว ฿'.number_format((float) $payout->amount, 2),
            'ค่าคอม '.$payout->commission_count.' ใบจอง โอนเข้าบัญชีของคุณแล้ว ขอบคุณที่ช่วยขายครับ',
            ['route' => 'partner', 'payout_id' => $payout->id],
        );

        return $payout;
    }

    // ── ตัวเลข ──────────────────────────────────────────────────────

    /**
     * ฐานคอม = ค่าทริปที่ลูกค้าจ่ายจริงหลังส่วนลด (รวมส่วนที่จ่ายด้วยบัตรของขวัญ)
     * ไม่รวมค่าบริการเสริม ค่าเช่าอุปกรณ์ และยอดที่ทีมงานยกเว้นให้
     */
    public function baseAmount(Booking $booking): float
    {
        $base = (float) $booking->total_amount
            + (float) $booking->voucher_amount
            - (float) $booking->waived_amount
            - (float) $booking->addons_total
            - (float) $booking->rentals_total;

        return round(max(0.0, $base), 2);
    }

    public function commissionFor(Booking $booking, string $type, float $rate): array
    {
        $seats = $booking->passengers()->count();
        $base = $this->baseAmount($booking);

        $amount = $type === Partner::TYPE_PER_SEAT
            // ต่อที่นั่งต้องไม่เกินเงินที่เข้าบริษัทจริง (ใบที่ยกเว้นค่าทริปทั้งหมดได้ 0)
            ? min($seats * $rate, $base)
            : $base * $rate / 100;

        return ['seats' => $seats, 'base_amount' => $base, 'amount' => round(max(0.0, $amount), 2)];
    }

    private function snapshot(Booking $booking, string $type, float $rate): array
    {
        $booking->loadMissing('schedule.trip');

        return $this->commissionFor($booking, $type, $rate) + [
            'booking_ref' => $booking->booking_ref,
            'trip_title' => $booking->schedule?->trip?->title,
            'departure_date' => $booking->schedule?->departure_date,
        ];
    }

    /**
     * @return array{pending: float, approved: float, paid: float, bookings: int, seats: int}
     */
    public function summary(Partner $partner): array
    {
        $rows = PartnerCommission::where('partner_id', $partner->id)
            ->where('status', '!=', PartnerCommission::STATUS_VOID)
            ->selectRaw('status, type, SUM(amount) as total, COUNT(*) as n, SUM(seats) as seats')
            ->groupBy('status', 'type')
            ->get();

        $sum = fn (string $status) => round((float) $rows->where('status', $status)->sum('total'), 2);
        $commissions = $rows->where('type', PartnerCommission::TYPE_COMMISSION);

        return [
            'pending' => $sum(PartnerCommission::STATUS_PENDING),
            // ยอดรอโอน (หักยอดคืนแล้ว)
            'approved' => $sum(PartnerCommission::STATUS_APPROVED),
            'paid' => round((float) PartnerPayout::where('partner_id', $partner->id)->sum('amount'), 2),
            'bookings' => (int) $commissions->sum('n'),
            'seats' => (int) $commissions->sum('seats'),
        ];
    }

    // ── แจ้งเตือน ───────────────────────────────────────────────────

    private function notifySale(Partner $partner, PartnerCommission $commission): void
    {
        $this->safeNotify(
            $partner->user_id,
            'partner_sale',
            'มีคนจองผ่านลิงก์ของคุณ 🎉',
            trim(($commission->trip_title ?? 'ทริป').' · '.$commission->seats.' ที่นั่ง · คอมประมาณ ฿'
                .number_format((float) $commission->amount, 2).' (ได้หลังเดินทาง)'),
            ['route' => 'partner', 'commission_id' => $commission->id],
        );
    }

    private function safeNotify(int $userId, string $type, string $title, string $body, array $data): void
    {
        try {
            SmartNotification::send($userId, $type, $title, $body, $data);
        } catch (\Throwable $e) {
            // แจ้งเตือนพลาดต้องไม่ทำให้การยืนยันใบจอง/บันทึกการโอนล้ม
            Log::warning('Partner notification failed', ['type' => $type, 'message' => $e->getMessage()]);
        }
    }
}
