<?php

namespace App\Services;

use App\Models\LoyaltyPointTransfer;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * โอนแต้มให้เพื่อน — กติกาฝั่งธุรกิจ (หาผู้รับ เพดานต่อวัน แจ้งเตือน)
 *
 * การย้ายแต้มจริงอยู่ที่ LoyaltyService::transfer() ซึ่งดูแลล็อตและวันหมดอายุ
 *
 * ผู้รับระบุได้สามแบบ: เบอร์โทร อีเมล หรือรหัสสมาชิก (ตัวเดียวกับรหัสชวนเพื่อน)
 * ลูกค้าที่ล็อกอินด้วย LINE หลายคนไม่มีทั้งเบอร์และอีเมลในระบบ รหัสสมาชิกจึงเป็น
 * ทางเดียวที่หาเขาเจอแน่ ๆ
 */
class LoyaltyTransferService
{
    public function __construct(
        private LoyaltyService $loyalty,
        private ReferralService $referrals,
    ) {}

    /** หาผู้ใช้จากเบอร์โทร / อีเมล / รหัสสมาชิก — ไม่เจอคืน null. */
    public function findRecipient(string $input): ?User
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        if (str_contains($input, '@')) {
            return User::whereRaw('lower(email) = ?', [Str::lower($input)])->first();
        }

        // รหัสสมาชิก 8 ตัว — ลองก่อนเบอร์ เพราะรหัสที่สุ่มได้เลขล้วนก็มีโอกาสเกิด
        if (preg_match('/^[A-Za-z0-9]{8}$/', $input)) {
            $byCode = User::where('referral_code', Str::upper($input))->first();

            if ($byCode || preg_match('/[A-Za-z]/', $input)) {
                return $byCode;
            }
        }

        if (PhoneNumber::normalise($input) === '') {
            return null;
        }

        // เบอร์ซ้ำกันหลายบัญชีได้ (สมัครใหม่ด้วยเบอร์เดิม) — ไม่เดาว่าเป็นใคร
        $matches = User::whereIn('phone', PhoneNumber::variants($input))->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * ข้อมูลให้ผู้โอนยืนยันว่า "ใช่คนนี้" ก่อนกดโอน — เหมือนพร้อมเพย์ที่โชว์ชื่อ
     * ปลายทางแบบปิดบางส่วน ไม่เปิดเผยชื่อเต็ม เบอร์ หรืออีเมลของคนที่ถูกค้นหา
     */
    public function preview(User $recipient): array
    {
        return [
            'display_name' => $this->maskedName($recipient),
            // รูปจาก ui-avatars มีชื่อเต็มอยู่ใน URL — ส่งเฉพาะรูปที่ผู้ใช้อัปโหลดเอง
            'avatar_url' => $recipient->avatar ? $recipient->avatar_url : null,
            'initial' => Str::upper(mb_substr(trim((string) $recipient->name), 0, 1)) ?: '?',
        ];
    }

    /** @throws \Exception ข้อความไทยพร้อมแสดงบนหน้าจอ */
    public function transfer(User $sender, User $recipient, int $points, ?string $message = null): LoyaltyPointTransfer
    {
        if (! config('loyalty.transfer.enabled', true)) {
            throw new \Exception('ระบบโอนแต้มปิดให้บริการชั่วคราว');
        }

        if ($sender->id === $recipient->id) {
            throw new \Exception('โอนแต้มให้ตัวเองไม่ได้');
        }

        $min = $this->minPoints();
        if ($points < $min) {
            throw new \Exception('โอนได้ครั้งละอย่างน้อย '.number_format($min).' แต้ม');
        }

        $message = trim((string) $message) ?: null;

        return DB::transaction(function () use ($sender, $recipient, $points, $message) {
            // ล็อกแถวผู้ใช้ไว้ก่อนนับยอดวันนี้ — กดโอนพร้อมกันหลายเครื่องจะได้ไม่ทะลุเพดาน
            User::whereKey($sender->id)->lockForUpdate()->first();

            $today = $this->sentToday($sender->id);

            if ($today['count'] >= $this->dailyMaxCount()) {
                throw new \Exception('วันนี้โอนแต้มครบ '.$this->dailyMaxCount().' ครั้งแล้ว ลองใหม่พรุ่งนี้');
            }

            $left = max(0, $this->dailyMaxPoints() - $today['points']);
            if ($points > $left) {
                throw new \Exception($left > 0
                    ? 'วันนี้โอนได้อีกไม่เกิน '.number_format($left).' แต้ม'
                    : 'วันนี้โอนแต้มครบเพดาน '.number_format($this->dailyMaxPoints()).' แต้มแล้ว ลองใหม่พรุ่งนี้');
            }

            $transfer = LoyaltyPointTransfer::create([
                'sender_id' => $sender->id,
                'recipient_id' => $recipient->id,
                'points' => $points,
                'message' => $message,
            ]);

            $this->loyalty->transfer(
                $sender->id,
                $recipient->id,
                $points,
                $transfer,
                'โอนแต้มให้ '.$this->maskedName($recipient),
                'ได้รับแต้มจาก '.$this->senderName($sender),
            );

            return $transfer;
        });
    }

    /** แจ้งผู้รับ — เรียกหลังธุรกรรมสำเร็จเท่านั้น จะได้ไม่มีการแจ้งเตือนของการโอนที่ล้มไป. */
    public function notifyRecipient(LoyaltyPointTransfer $transfer): void
    {
        $sender = $transfer->sender;
        $body = $this->senderName($sender).' โอนให้คุณ '.number_format($transfer->points).' แต้ม';

        if ($transfer->message) {
            $body .= ' — "'.$transfer->message.'"';
        }

        SmartNotification::send(
            $transfer->recipient_id,
            'loyalty_points_received',
            'คุณได้รับแต้มจากเพื่อน',
            $body,
            [
                'route' => 'loyalty',
                'transfer_id' => $transfer->id,
                'points' => $transfer->points,
            ],
        );
    }

    /**
     * เพดานและยอดที่ใช้ไปวันนี้ — ส่งให้หน้าจอปิดปุ่ม/บอกเพดานได้เองก่อนกดโอน
     *
     * @return array{enabled: bool, min_points: int, daily_max_points: int, daily_max_count: int, sent_today_points: int, sent_today_count: int, remaining_today_points: int}
     */
    public function limitsFor(User $user): array
    {
        $today = $this->sentToday($user->id);

        return [
            'enabled' => (bool) config('loyalty.transfer.enabled', true),
            'min_points' => $this->minPoints(),
            'daily_max_points' => $this->dailyMaxPoints(),
            'daily_max_count' => $this->dailyMaxCount(),
            'sent_today_points' => $today['points'],
            'sent_today_count' => $today['count'],
            'remaining_today_points' => $today['count'] >= $this->dailyMaxCount()
                ? 0
                : max(0, $this->dailyMaxPoints() - $today['points']),
        ];
    }

    /** รายการโอนเข้า-ออกล่าสุดของผู้ใช้ ในมุมมองของผู้ใช้คนนั้น. */
    public function historyFor(User $user, int $limit = 30): array
    {
        return LoyaltyPointTransfer::with(['sender:id,name,nickname', 'recipient:id,name,nickname'])
            ->where(fn ($q) => $q->where('sender_id', $user->id)->orWhere('recipient_id', $user->id))
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (LoyaltyPointTransfer $t) use ($user) {
                $outgoing = $t->sender_id === $user->id;

                return [
                    'id' => $t->id,
                    'direction' => $outgoing ? 'out' : 'in',
                    'points' => $t->points,
                    'counterpart_name' => $outgoing
                        ? $this->maskedName($t->recipient)
                        : $this->senderName($t->sender),
                    'message' => $t->message,
                    'created_at' => $t->created_at?->toISOString(),
                ];
            })
            ->all();
    }

    /** รหัสสมาชิกของผู้ใช้เอง — ให้เพื่อนเอาไปกรอกตอนจะโอนแต้มมาให้. */
    public function memberCodeFor(User $user): string
    {
        return $this->referrals->codeFor($user);
    }

    /** "สมชาย ก." — ชื่อต้น + อักษรแรกของนามสกุล. */
    public function maskedName(?User $user): string
    {
        $parts = preg_split('/\s+/u', trim((string) $user?->name)) ?: [];
        $parts = array_values(array_filter($parts, fn ($p) => $p !== ''));

        if ($parts === []) {
            return 'สมาชิก';
        }

        // คำนำหน้าชื่อไม่ใช่ชื่อ — "นาย ก." บอกอะไรผู้โอนไม่ได้เลย
        if (count($parts) > 1 && in_array($parts[0], ['นาย', 'นาง', 'นางสาว', 'น.ส.', 'ด.ช.', 'ด.ญ.', 'Mr.', 'Mrs.', 'Ms.', 'Miss'], true)) {
            array_shift($parts);
        }

        $first = $parts[0];

        return isset($parts[1]) ? $first.' '.mb_substr($parts[1], 0, 1).'.' : $first;
    }

    /** ชื่อผู้โอนที่ผู้รับเห็น — ชื่อเล่นถ้ามี (เพื่อนรู้จักกันด้วยชื่อเล่น) ไม่งั้นชื่อแบบย่อ. */
    private function senderName(?User $sender): string
    {
        $nickname = trim((string) $sender?->nickname);

        return $nickname !== '' ? $nickname.' ('.$this->maskedName($sender).')' : $this->maskedName($sender);
    }

    /** @return array{points: int, count: int} */
    private function sentToday(int $userId): array
    {
        // นับตามวันไทย — เซิร์ฟเวอร์เก็บเวลาเป็น UTC
        $start = Carbon::now('Asia/Bangkok')->startOfDay()->utc();

        $row = LoyaltyPointTransfer::where('sender_id', $userId)
            ->where('created_at', '>=', $start)
            ->selectRaw('count(*) as c, coalesce(sum(points), 0) as p')
            ->first();

        return ['points' => (int) ($row->p ?? 0), 'count' => (int) ($row->c ?? 0)];
    }

    private function minPoints(): int
    {
        return max(1, (int) config('loyalty.transfer.min_points', 10));
    }

    private function dailyMaxPoints(): int
    {
        return max(1, (int) config('loyalty.transfer.daily_max_points', 1000));
    }

    private function dailyMaxCount(): int
    {
        return max(1, (int) config('loyalty.transfer.daily_max_count', 5));
    }
}
