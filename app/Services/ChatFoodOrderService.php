<?php

namespace App\Services;

use App\Events\ChatFoodRoundUpdated;
use App\Events\ChatMessageSent;
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
    public function __construct(private ChatService $chatService) {}

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

        return collect($lines)
            ->map(fn ($l) => [...$l, 'people' => array_values(array_unique($l['people']))])
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
            ])->values()->all(),
            'summary' => $summary->all(),
        ];
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
