<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ผูกใบจองที่ทีมงานเปิดให้ เข้ากับบัญชีที่ลูกค้าสมัครเอง
 *
 * ปัญหาที่แก้: แอดมินเปิดใบจองแทนลูกค้า ระบบสร้าง "บัญชีเงา" ให้ใบจองนั้นเกาะไว้
 * (อีเมลปลอม รหัสผ่านสุ่ม) พอลูกค้าไปสมัครเองในแอปด้วยอีเมลจริง ก็ได้บัญชีคนละใบ
 * ใบจองจึงไม่ขึ้นในหน้า "การจองของฉัน" ทั้งที่จ่ายเงินแล้ว
 *
 * มีสองประตูโดยตั้งใจ เพราะลูกค้าสองแบบมีหลักฐานคนละอย่างในมือ:
 *
 * 1. **เลขที่จอง + เบอร์** — คนที่มีเลขที่จองอยู่ในมือ (จาก SMS/ใบเสร็จ/ทีมงานบอก)
 *    หลักฐานชุดเดียวกับหน้า "ค้นหาการจอง" ของคนที่ยังไม่ล็อกอิน
 * 2. **ลิงก์เปิดใช้บัญชี** — ทีมงานส่งลิงก์เข้าเบอร์ของลูกค้าเอง การถือลิงก์ที่ส่ง
 *    เข้าเบอร์ตัวเองคือหลักฐานที่แน่นที่สุดที่ระบบนี้มี (ยังไม่มี OTP)
 *
 * สิ่งที่ **ไม่** ทำ: ย้ายใบจองให้อัตโนมัติเพียงเพราะเบอร์ตรงกันตอนสมัคร — เบอร์ที่
 * กรอกตอนสมัครไม่เคยถูกยืนยัน และในใบจองมีเลขบัตรประชาชน/ประวัติแพ้อาหาร/โรคประจำตัว
 * ของผู้เดินทางทุกคน การเดาเบอร์ถูกจึงไม่ควรพอที่จะเห็นของพวกนั้น เบอร์ที่ตรงกันใช้ได้
 * แค่บอกว่า "น่าจะมีใบจองของคุณอยู่ N ใบ" แล้วให้ยืนยันด้วยหลักฐานจริงอีกที
 */
class AccountClaimService
{
    /** สถานะที่ยังมีความหมายให้ผูกเข้าบัญชี — ใบที่ยกเลิกไปแล้วไม่ต้องตามเก็บ */
    public const CLAIMABLE_STATUSES = ['pending', 'confirmed', 'completed'];

    public function __construct(private readonly SmsService $sms) {}

    /**
     * ใบจองที่ "น่าจะ" เป็นของผู้ใช้คนนี้ — ตัดสินจากเบอร์อย่างเดียว จึงใช้ได้แค่
     * เป็นตัวชวนให้กดยืนยัน ห้ามเอาไปคืนรายละเอียดที่เป็นข้อมูลส่วนบุคคล
     *
     * @return Collection<int, Booking>
     */
    public function claimableFor(User $user): Collection
    {
        $variants = PhoneNumber::variants($user->phone);

        if (PhoneNumber::normalise($user->phone) === '') {
            return collect();
        }

        return Booking::query()
            ->whereIn('status', self::CLAIMABLE_STATUSES)
            ->where('user_id', '!=', $user->id)
            ->whereHas('user', fn ($q) => $q->where('is_shadow', true))
            ->where(function ($query) use ($variants) {
                $query->whereHas('user', fn ($q) => $q->whereIn('phone', $variants))
                    ->orWhereHas('passengers', fn ($q) => $q->whereIn('phone', $variants));
            })
            ->with(['user:id,phone', 'passengers:id,booking_id,phone', 'schedule.trip'])
            ->latest('id')
            ->get()
            // whereIn จับได้เฉพาะรูปแบบที่คาดไว้ ตัวที่หลุดมาต้องเทียบซ้ำแบบ normalise
            ->filter(fn (Booking $booking) => $this->phoneBelongsToBooking($booking, $user->phone))
            ->values();
    }

    /**
     * ประตูที่ 1 — เลขที่จอง + เบอร์ (4 ตัวท้ายก็พอ เหมือนหน้าค้นหาการจอง)
     *
     * @throws \Exception
     */
    public function claimByReference(User $user, string $reference, string $phone): Booking
    {
        $booking = Booking::with(['user', 'passengers', 'schedule.trip'])
            ->where('booking_ref', strtoupper(trim($reference)))
            ->first();

        if (! $booking) {
            throw new \Exception('ไม่พบเลขที่การจองนี้ กรุณาตรวจสอบอีกครั้ง');
        }

        if ((int) $booking->user_id === (int) $user->id) {
            throw new \Exception('การจองนี้อยู่ในบัญชีของคุณอยู่แล้ว');
        }

        if (! in_array($booking->status, self::CLAIMABLE_STATUSES, true)) {
            throw new \Exception('การจองนี้ถูกยกเลิกไปแล้ว ไม่สามารถผูกกับบัญชีได้');
        }

        // เจ้าของปัจจุบันต้องเป็นบัญชีเงาเท่านั้น — ใบที่อยู่ในบัญชีจริงของคนอื่น
        // ต้องให้ทีมงานเป็นคนย้ายให้ ไม่ใช่ใครก็ได้ที่รู้เลขที่จอง
        if (! $booking->user || ! $booking->user->is_shadow) {
            throw new \Exception('การจองนี้ผูกกับบัญชีอื่นอยู่แล้ว กรุณาติดต่อทีมงาน');
        }

        $last4 = PhoneNumber::last4($phone);

        if (strlen($last4) < 4 || ! $this->last4BelongsToBooking($booking, $last4)) {
            throw new \Exception('เบอร์โทรไม่ตรงกับข้อมูลการจอง กรุณาตรวจสอบอีกครั้ง');
        }

        $this->attach($booking, $user, 'booking_ref');

        return $booking->fresh(['schedule.trip']);
    }

    /**
     * ประตูที่ 2 — ลิงก์เปิดใช้บัญชีที่ส่งเข้าเบอร์ลูกค้า
     *
     * คืนบัญชีเงาที่ลิงก์นั้นชี้ไป ยังไม่แตะอะไรทั้งสิ้น
     */
    public function resolveClaimToken(string $token): ?User
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return User::where('claim_token', $token)->where('is_shadow', true)->first();
    }

    /** ใบจองทั้งหมดที่บัญชีเงาใบนี้ถืออยู่ — ใช้โชว์บนหน้าเปิดใช้บัญชี */
    public function bookingsOf(User $shadow): Collection
    {
        return Booking::where('user_id', $shadow->id)
            ->whereIn('status', self::CLAIMABLE_STATUSES)
            ->with('schedule.trip')
            ->latest('id')
            ->get();
    }

    /** สร้าง/คืน token เดิม เพื่อให้ลิงก์ที่ส่งไปแล้วยังใช้ได้เมื่อส่งซ้ำ */
    public function issueClaimToken(User $shadow): string
    {
        if (! $shadow->claim_token) {
            $shadow->forceFill(['claim_token' => Str::random(40)])->save();
        }

        return $shadow->claim_token;
    }

    public function claimUrl(User $shadow): string
    {
        return url('/claim/'.$this->issueClaimToken($shadow));
    }

    /**
     * ส่งลิงก์เปิดใช้บัญชีให้ลูกค้าทาง SMS — เจ้าของใบจองต้องเป็นบัญชีเงา
     * คืน false เมื่อใบจองนี้ไม่เข้าเงื่อนไข (เจ้าของสมัครเองแล้ว)
     *
     * $force คือปุ่ม "ส่งซ้ำ" ของแอดมิน — ปกติข้อความนี้ถูกกันซ้ำต่อใบจอง เพราะ
     * เส้นทางอัตโนมัติตอนเปิดใบจองไม่ควรยิงซ้ำถ้าแอดมินกดแก้ใบจองอีกรอบ
     */
    public function sendClaimLink(Booking $booking, bool $force = false): bool
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);
        $owner = $booking->user;

        if (! $owner || ! $owner->is_shadow) {
            return false;
        }

        $this->sms->sendAccountClaimLink(
            $booking,
            $this->claimUrl($owner),
            $force ? 'resend:'.now()->timestamp : 'default',
        );
        $owner->forceFill(['claim_token_sent_at' => now()])->save();

        return true;
    }

    /**
     * ลูกค้าเปิดลิงก์แล้วตั้งรหัสผ่าน — "สวมบัญชีเดิม" ไม่ใช่สร้างใบใหม่แล้วย้ายของ
     * เพราะ user_id ของบัญชีนี้ถูกอ้างถึงจากใบจอง แต้มสะสม แชท และการแจ้งเตือน
     * การเขียนทับอีเมล/รหัสผ่านลงแถวเดิมจึงปลอดภัยกว่าการไล่ย้าย FK ทุกตาราง
     *
     * @param  array{email: string, password: string, name?: string|null}  $data
     *
     * @throws \Exception
     */
    public function activate(User $shadow, array $data): User
    {
        $email = mb_strtolower(trim($data['email']));

        if (User::where('email', $email)->where('id', '!=', $shadow->id)->exists()) {
            throw new \Exception('อีเมลนี้มีบัญชีอยู่แล้ว กรุณาเข้าสู่ระบบด้วยบัญชีเดิมเพื่อรับการจอง');
        }

        $attributes = [
            'email' => $email,
            'password' => Hash::make($data['password']),
            'is_shadow' => false,
            // ลิงก์ใช้ได้ครั้งเดียว — ที่เหลือคือรหัสผ่านที่ลูกค้าตั้งเอง
            'claim_token' => null,
        ];

        if (filled($data['name'] ?? null)) {
            $attributes['name'] = $data['name'];
        }

        $shadow->forceFill($attributes)->save();

        if (! $shadow->hasRole('customer')) {
            $shadow->assignRole('customer');
        }

        Log::info('Shadow account activated', ['user_id' => $shadow->id]);

        return $shadow->fresh();
    }

    /**
     * ลูกค้าที่ "สมัครไปแล้ว" เปิดลิงก์ — ย้ายใบจองทั้งหมดจากบัญชีเงามาบัญชีจริง
     * แล้วปิดบัญชีเงาไม่ให้ถูกใช้ซ้ำ คืนจำนวนใบจองที่ย้ายมา
     */
    public function mergeInto(User $target, User $shadow): int
    {
        if ($target->id === $shadow->id) {
            return 0;
        }

        return DB::transaction(function () use ($target, $shadow) {
            $bookings = Booking::where('user_id', $shadow->id)->get();

            foreach ($bookings as $booking) {
                $this->attach($booking, $target, 'claim_link');
            }

            // ปิดบัญชีเงาแทนการลบ — แถวอื่น (SmsLog, การแจ้งเตือนเก่า) ยังชี้มาที่ id นี้
            $shadow->forceFill([
                'claim_token' => null,
                'is_shadow' => false,
                'email' => 'merged_'.$shadow->id.'_'.Str::random(6).'@luilaykhao.com',
                'password' => Hash::make(Str::random(32)),
            ])->save();

            Log::info('Shadow account merged', [
                'shadow_user_id' => $shadow->id,
                'target_user_id' => $target->id,
                'bookings' => $bookings->count(),
            ]);

            return $bookings->count();
        });
    }

    /**
     * ย้ายใบจองเข้าบัญชี — แต้มสะสมย้ายตามเองใน BookingObserver ที่จับ user_id
     * ที่ต้องเก็บกวาดตรงนี้คือแถว "เพื่อนร่วมใบจอง" ของคนคนเดียวกัน ไม่งั้นเขาจะ
     * เป็นทั้งเจ้าของและผู้ร่วมเดินทางในใบเดียวกัน แล้วใบจองขึ้นซ้ำสองครั้งในแอป
     */
    public function attach(Booking $booking, User $user, string $via): void
    {
        $previousUserId = $booking->user_id;

        DB::transaction(function () use ($booking, $user) {
            $booking->update(['user_id' => $user->id]);

            BookingMember::where('booking_id', $booking->id)
                ->where('user_id', $user->id)
                ->where('role', BookingMember::ROLE_COMPANION)
                ->update(['status' => BookingMember::STATUS_REVOKED]);
        });

        Log::info('Booking claimed by customer', [
            'booking_ref' => $booking->booking_ref,
            'from_user_id' => $previousUserId,
            'to_user_id' => $user->id,
            'via' => $via,
        ]);
    }

    /** เบอร์นี้อยู่ในใบจองนี้ไหม (เจ้าของใบ หรือผู้เดินทางคนใดคนหนึ่ง) */
    private function phoneBelongsToBooking(Booking $booking, ?string $phone): bool
    {
        if (PhoneNumber::matches($booking->user?->phone, $phone)) {
            return true;
        }

        return $booking->passengers
            ->contains(fn ($passenger) => PhoneNumber::matches($passenger->phone, $phone));
    }

    private function last4BelongsToBooking(Booking $booking, string $last4): bool
    {
        if (PhoneNumber::endsWithLast4($booking->user?->phone, $last4)) {
            return true;
        }

        return $booking->passengers
            ->contains(fn ($passenger) => PhoneNumber::endsWithLast4($passenger->phone, $last4));
    }
}
