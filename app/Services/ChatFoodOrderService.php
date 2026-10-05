<?php

namespace App\Services;

use App\Events\ChatFoodRoundUpdated;
use App\Events\ChatMessageSent;
use App\Jobs\SendChatPushJob;
use App\Models\ChatFoodOrder;
use App\Models\ChatFoodRound;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * รับออเดอร์อาหารผ่านห้องแชท — แวะร้านตามสั่งขากลับ แต่ละคนพิมพ์เมนูของตัวเอง
 * ระหว่างนั่งรถ แล้วสตาฟถือรายการรวม ("กะเพราหมูสับไข่ดาว × 4") ไปสั่งร้านทีเดียว
 * แทนการลงจากรถแล้วต่อคิวสั่งทีละคน
 *
 * ทุกคนในห้องเห็นออเดอร์ของกันและกัน (ทริปกลุ่ม + ช่วยให้ "เอาเหมือนเพื่อน" ได้)
 * สตาฟเป็นคนเปิด/ปิดรอบ และจดแทนคนที่ไม่ได้ใช้แอปได้
 */
class ChatFoodOrderService
{
    public function __construct(
        private ChatService $chatService,
        private PromptPayService $promptPay,
        private FcmService $fcm,
    ) {}

    public function open(
        User $user,
        TripSchedule $schedule,
        string $title,
        ?string $note = null,
        ?int $durationMinutes = null,
    ): ChatFoodRound {
        $round = DB::transaction(function () use ($user, $schedule, $title, $note, $durationMinutes) {
            $round = ChatFoodRound::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $user->id,
                'title' => $title,
                'note' => $note ?: null,
                'closes_at' => $durationMinutes
                    ? now()->addMinutes(min($durationMinutes, ChatFoodRound::MAX_MINUTES))
                    : null,
            ]);

            // body อ่านรู้เรื่องเองในรายการแชท / push / เว็บที่ยังไม่รู้จักการ์ดนี้
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'sender_role' => $this->chatService->senderRole($user, $schedule),
                'body' => "🍜 รับออเดอร์อาหาร: {$title}",
            ]);

            $round->update(['message_id' => $message->id]);

            return $round;
        });

        $message = $round->message()->with(['user', 'replyTo.user', 'reactions', 'foodRound.orders.user'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $round->fresh(['orders.user']);
    }

    /**
     * ส่ง/แก้ออเดอร์ของตัวเอง — ส่งซ้ำ = แทนที่ของเดิม, skipped = "รอบนี้ไม่สั่ง"
     *
     * @param  array<int, array{name?: mixed, qty?: mixed}>  $items
     */
    public function submit(User $user, ChatFoodRound $round, array $items, bool $skipped = false): ChatFoodRound
    {
        $this->assertOpen($round);
        $clean = $skipped ? [] : $this->cleanItems($items);

        if (! $skipped && $clean === []) {
            throw new \Exception('พิมพ์เมนูอย่างน้อย 1 อย่าง หรือกด "รอบนี้ไม่สั่ง"');
        }

        ChatFoodOrder::updateOrCreate(
            ['round_id' => $round->id, 'user_id' => $user->id],
            ['items' => $clean, 'skipped' => $skipped, 'entered_by_id' => $user->id],
        );

        return $this->broadcastUpdate($round);
    }

    /**
     * สตาฟจดแทนคนที่ไม่ได้ใช้แอป — แยกเป็นแถวของชื่อนั้น ไม่ผูกบัญชีใคร
     *
     * @param  array<int, array{name?: mixed, qty?: mixed}>  $items
     */
    public function addOnBehalf(User $staff, ChatFoodRound $round, string $name, array $items): ChatFoodRound
    {
        $this->assertOpen($round);
        $clean = $this->cleanItems($items);

        if ($clean === []) {
            throw new \Exception('พิมพ์เมนูอย่างน้อย 1 อย่าง');
        }

        ChatFoodOrder::create([
            'round_id' => $round->id,
            'user_id' => null,
            'guest_name' => $name,
            'items' => $clean,
            'skipped' => false,
            'entered_by_id' => $staff->id,
        ]);

        return $this->broadcastUpdate($round);
    }

    public function remove(ChatFoodOrder $order): ChatFoodRound
    {
        $round = $order->round;
        $this->assertOpen($round);
        $order->delete();

        return $this->broadcastUpdate($round);
    }

    /** ปิดรับ — สตาฟกดเมื่อจะลงไปสั่ง แล้วประกาศยอดรวมเข้าห้อง */
    public function close(ChatFoodRound $round): ChatFoodRound
    {
        if ($round->closed_at === null) {
            $round->update(['closed_at' => now()]);
        }

        $fresh = $this->broadcastUpdate($round);
        $this->announceClosed($fresh);

        return $fresh;
    }

    /** เปิดรับต่อ — มีคนตามมาขอสั่งเพิ่มหลังปิดไปแล้ว */
    public function reopen(ChatFoodRound $round): ChatFoodRound
    {
        $round->update(['closed_at' => null, 'closes_at' => null, 'announced_at' => null]);

        return $this->broadcastUpdate($round);
    }

    /** รอบที่หมดเวลารับเองแต่ยังไม่ได้ประกาศ — เรียกจาก SettleChatPollsJob ทุกนาที */
    public function settleDue(): int
    {
        $settled = 0;

        ChatFoodRound::whereNull('announced_at')
            ->whereNotNull('message_id')
            ->where('closes_at', '<=', now())
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (ChatFoodRound $round) use (&$settled) {
                try {
                    $this->announceClosed($this->broadcastUpdate($round));
                    $settled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: ปิดรอบสั่งอาหารไม่สำเร็จ', [
                        'round_id' => $round->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $settled;
    }

    /**
     * รายการรวมสำหรับสั่งร้าน — เมนูเดียวกันรวมเป็นบรรทัดเดียว เทียบแบบไม่สนช่องว่าง
     * และตัวพิมพ์ ("กะเพราหมูสับ  ไข่ดาว" = "กะเพราหมูสับ ไข่ดาว")
     *
     * @return Collection<int, array{name: string, qty: int, people: array<int, string>}>
     */
    public function summary(ChatFoodRound $round): Collection
    {
        $round->loadMissing('orders.user');
        $lines = [];

        foreach ($round->orders as $order) {
            if ($order->skipped) {
                continue;
            }
            foreach ($this->itemsOf($order) as $item) {
                $key = $this->normalize($item['name']);
                $lines[$key] ??= ['name' => $item['name'], 'qty' => 0, 'people' => []];
                $lines[$key]['qty'] += $item['qty'];
                $lines[$key]['people'][] = $order->displayName();
            }
        }

        $prices = $this->prices($round);

        return collect($lines)
            ->map(fn ($l, $key) => [
                ...$l,
                'people' => array_values(array_unique($l['people'])),
                'price' => $prices[$key] ?? null,
            ])
            ->sortByDesc('qty')
            ->values();
    }

    /**
     * payload ของรอบสำหรับ API / broadcast — ไม่ผูกกับผู้ดู
     * (แอปหา "ออเดอร์ของฉัน" จาก user_id เอง เหมือนการ์ดโพล)
     *
     * @return array<string, mixed>
     */
    public function present(ChatFoodRound $round): array
    {
        $round->loadMissing('orders.user');
        $summary = $this->summary($round);

        return [
            'id' => $round->id,
            'title' => $round->title,
            'note' => $round->note,
            'is_closed' => $round->isClosed(),
            'closes_at' => $round->closes_at?->toISOString(),
            'created_by_id' => $round->created_by_id ? (int) $round->created_by_id : null,
            'order_count' => $round->orders->where('skipped', false)->count(),
            'skipped_count' => $round->orders->where('skipped', true)->count(),
            'dish_count' => (int) $summary->sum('qty'),
            'orders' => $round->orders->map(fn (ChatFoodOrder $o) => [
                'id' => $o->id,
                'user_id' => $o->user_id ? (int) $o->user_id : null,
                'name' => $o->displayName(),
                'avatar_url' => $o->user?->avatar_url,
                'is_guest' => $o->user_id === null,
                'skipped' => (bool) $o->skipped,
                'items' => $this->itemsOf($o),
                'updated_at' => $o->updated_at?->toISOString(),
                ...($round->billed_at ? $this->presentBill($round, $o) : []),
            ])->values()->all(),
            'summary' => $summary->all(),
            'billing' => $round->billed_at ? $this->billingSummary($round, $summary) : null,
        ];
    }

    // ── หารบิล ──────────────────────────────────────────────────────────────

    /**
     * สตาฟใส่ราคาต่อเมนู (+ พร้อมเพย์ที่จะให้โอนคืน) — ส่งซ้ำได้ แก้ราคาทีหลังได้
     * notify = ประกาศเข้าห้องและเด้งบอกแต่ละคนว่าต้องโอนเท่าไร
     *
     * @param  array<int, array{name?: mixed, price?: mixed}>  $prices
     */
    public function bill(
        User $staff,
        ChatFoodRound $round,
        array $prices,
        ?string $promptPayId,
        ?string $payeeName,
        bool $notify,
    ): ChatFoodRound {
        $map = $this->prices($round);
        foreach ($prices as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || ! is_numeric($p['price'] ?? null)) {
                continue;
            }
            $map[$this->normalize($name)] = round(min(max((float) $p['price'], 0), ChatFoodRound::MAX_PRICE), 2);
        }

        $round->update([
            'prices' => $map,
            'promptpay_id' => $promptPayId ? preg_replace('/\D/', '', $promptPayId) : null,
            'payee_name' => $payeeName ?: null,
            'billed_at' => $round->billed_at ?? now(),
        ]);

        $fresh = $this->broadcastUpdate($round);

        if ($notify) {
            $this->announceBill($fresh);
        }

        return $fresh;
    }

    /** ลูกค้ากด "โอนแล้ว" (หรือถอน) — สตาฟยังต้องเช็กยอดเข้าแล้วยืนยันเอง */
    public function claimPaid(User $user, ChatFoodRound $round, bool $claimed): ChatFoodRound
    {
        if (! $round->billed_at) {
            throw new \Exception('ทีมงานยังไม่ได้ใส่ยอดค่าอาหาร');
        }

        $order = ChatFoodOrder::where('round_id', $round->id)->where('user_id', $user->id)->first();
        if (! $order || $order->skipped) {
            throw new \Exception('คุณไม่ได้สั่งอาหารรอบนี้');
        }

        $order->update(['paid_claimed_at' => $claimed ? now() : null]);

        return $this->broadcastUpdate($round);
    }

    /** สตาฟยืนยันว่าได้รับเงินแล้ว (หรือยกเลิก) — จำยอดที่ได้ไว้ เผื่อราคาเปลี่ยนทีหลัง */
    public function setPaid(User $staff, ChatFoodOrder $order, bool $paid): ChatFoodRound
    {
        $round = $order->round;
        if (! $round->billed_at) {
            throw new \Exception('ใส่ราคาก่อนแล้วค่อยเช็กการจ่ายเงิน');
        }

        $order->update($paid
            ? ['paid_at' => now(), 'paid_amount' => $this->amountOf($round, $order)['amount'], 'paid_marked_by_id' => $staff->id]
            : ['paid_at' => null, 'paid_amount' => null, 'paid_claimed_at' => null, 'paid_marked_by_id' => null]);

        return $this->broadcastUpdate($round);
    }

    /**
     * @return array{amount: float, complete: bool}
     */
    private function amountOf(ChatFoodRound $round, ChatFoodOrder $order): array
    {
        if ($order->skipped) {
            return ['amount' => 0.0, 'complete' => true];
        }

        $prices = $this->prices($round);
        $amount = 0.0;
        $complete = true;

        foreach ($this->itemsOf($order) as $item) {
            $price = $prices[$this->normalize($item['name'])] ?? null;
            if ($price === null) {
                $complete = false;

                continue;
            }
            $amount += $price * $item['qty'];
        }

        return ['amount' => round($amount, 2), 'complete' => $complete];
    }

    /**
     * ยอดของออเดอร์หนึ่ง: unpriced (ยังมีเมนูไม่มีราคา) / unpaid / claimed (แจ้งโอนแล้ว)
     * / paid / short (จ่ายแล้วแต่ราคาขึ้นทีหลัง — ขาดอีก balance)
     *
     * @return array<string, mixed>
     */
    private function presentBill(ChatFoodRound $round, ChatFoodOrder $order): array
    {
        ['amount' => $amount, 'complete' => $complete] = $this->amountOf($round, $order);
        $paid = $order->paid_at ? (float) $order->paid_amount : 0.0;
        $balance = round(max($amount - $paid, 0), 2);

        $status = match (true) {
            $order->skipped => 'none',
            ! $complete => 'unpriced',
            $order->paid_at !== null && $balance <= 0 => 'paid',
            $order->paid_at !== null => 'short',
            $order->paid_claimed_at !== null => 'claimed',
            $amount <= 0 => 'none',
            default => 'unpaid',
        };

        return [
            'amount' => $amount,
            'amount_complete' => $complete,
            'paid_amount' => $order->paid_at ? $paid : null,
            'balance' => $complete ? $balance : null,
            'pay_status' => $status,
            // QR ของยอดที่ยังค้าง — แอปวาดเป็นภาพ สแกนจากแอปธนาคารได้เลย
            'promptpay_payload' => ($round->promptpay_id && $complete && $balance > 0 && in_array($status, ['unpaid', 'claimed', 'short'], true))
                ? $this->promptPay->buildPayload($round->promptpay_id, $balance)
                : null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $summary
     * @return array<string, mixed>
     */
    private function billingSummary(ChatFoodRound $round, Collection $summary): array
    {
        $total = 0.0;
        $collected = 0.0;
        $outstanding = 0.0;
        $paidCount = 0;
        $owingCount = 0;

        foreach ($round->orders as $order) {
            if ($order->skipped) {
                continue;
            }
            $bill = $this->presentBill($round, $order);
            $total += $bill['amount'];
            $collected += $bill['paid_amount'] ?? 0;
            $outstanding += $bill['balance'] ?? 0;
            if ($bill['pay_status'] === 'paid') {
                $paidCount++;
            } elseif (in_array($bill['pay_status'], ['unpaid', 'claimed', 'short', 'unpriced'], true)) {
                $owingCount++;
            }
        }

        return [
            'promptpay_id' => $round->promptpay_id,
            'payee_name' => $round->payee_name,
            'total' => round($total, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($outstanding, 2),
            'paid_count' => $paidCount,
            'owing_count' => $owingCount,
            'unpriced' => $summary->whereNull('price')->pluck('name')->values()->all(),
            'billed_at' => $round->billed_at?->toISOString(),
        ];
    }

    private function announceBill(ChatFoodRound $round): void
    {
        $round->loadMissing('orders.user');
        $summary = $this->summary($round);
        $billing = $this->billingSummary($round, $summary);
        $payee = $round->payee_name ?: 'ทีมงาน';

        if ($round->schedule) {
            $this->chatService->postSystem(
                $round->schedule,
                "💸 ยอดค่าอาหาร “{$round->title}” รวม ฿".$this->baht($billing['total'])
                    ." — ดูยอดของตัวเองในการ์ด แล้วโอนให้{$payee}ผ่านพร้อมเพย์ได้เลยครับ",
            );
        }

        $tripTitle = $round->schedule?->trip?->title ?? 'ทริปของคุณ';

        foreach ($round->orders as $order) {
            if (! $order->user_id) {
                continue;
            }
            $bill = $this->presentBill($round, $order);
            if (! in_array($bill['pay_status'], ['unpaid', 'short'], true) || $bill['balance'] <= 0) {
                continue;
            }

            try {
                $this->fcm->sendToUser(
                    (int) $order->user_id,
                    '💸 ค่าอาหารของคุณ ฿'.$this->baht($bill['balance']),
                    "{$round->title} — โอนให้{$payee}ผ่านพร้อมเพย์ในแชทได้เลย · {$tripTitle}",
                    [
                        'type' => 'food_bill',
                        'route' => 'chat',
                        'schedule_id' => (string) $round->schedule_id,
                        'message_id' => (string) $round->message_id,
                    ],
                    ['android_channel' => SendChatPushJob::ANDROID_CHANNEL],
                );
            } catch (\Throwable $e) {
                Log::warning('ChatFoodOrderService: ส่ง push ยอดค่าอาหารไม่สำเร็จ', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return array<string, float> */
    private function prices(ChatFoodRound $round): array
    {
        $prices = $round->prices;
        if (is_string($prices)) {
            $prices = json_decode($prices, true);
        }

        return is_array($prices) ? array_map('floatval', $prices) : [];
    }

    private function baht(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2);
    }

    private function assertOpen(?ChatFoodRound $round): void
    {
        if (! $round || $round->isClosed()) {
            throw new \Exception('รอบนี้ปิดรับออเดอร์แล้ว ทักน้องสตาฟในแชทได้เลยครับ');
        }
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{name: string, qty: int}>
     */
    private function cleanItems(array $items): array
    {
        return collect($items)
            ->filter(fn ($i) => is_array($i))
            ->map(fn ($i) => [
                'name' => trim(preg_replace('/\s+/u', ' ', (string) ($i['name'] ?? ''))),
                'qty' => min(max((int) ($i['qty'] ?? 1), 1), ChatFoodRound::MAX_QTY),
            ])
            ->filter(fn ($i) => $i['name'] !== '')
            ->take(ChatFoodRound::MAX_ITEMS)
            ->values()
            ->all();
    }

    /** @return array<int, array{name: string, qty: int}> */
    private function itemsOf(ChatFoodOrder $order): array
    {
        $items = $order->items;
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        return is_array($items) ? $this->cleanItems($items) : [];
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name));
    }

    private function broadcastUpdate(ChatFoodRound $round): ChatFoodRound
    {
        $fresh = $round->fresh(['orders.user']);

        broadcast(new ChatFoodRoundUpdated(
            $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }

    /** ประกาศยอดรวมครั้งเดียวต่อการปิดหนึ่งครั้ง (เปิดรับต่อแล้วปิดใหม่ = ประกาศใหม่) */
    private function announceClosed(ChatFoodRound $round): void
    {
        if (! $round->isClosed() || $round->announced_at !== null) {
            return;
        }

        $claimed = ChatFoodRound::whereKey($round->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]);

        if ($claimed === 0 || ! $round->schedule) {
            return;
        }

        $dishes = (int) $this->summary($round)->sum('qty');
        $people = $round->orders->where('skipped', false)->count();

        $this->chatService->postSystem(
            $round->schedule,
            $dishes === 0
                ? "🍜 ปิดรับออเดอร์ “{$round->title}” แล้ว — รอบนี้ยังไม่มีใครสั่งครับ"
                : "🍜 ปิดรับออเดอร์ “{$round->title}” แล้ว — รวม {$dishes} จาน จาก {$people} คน "
                    .'น้องสตาฟกำลังไปสั่งให้นะครับ',
        );
    }
}
