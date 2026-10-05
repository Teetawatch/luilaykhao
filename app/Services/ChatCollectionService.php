<?php

namespace App\Services;

use App\Events\ChatCollectionUpdated;
use App\Events\ChatMessageSent;
use App\Models\ChatCollection;
use App\Models\ChatCollectionDue;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * เก็บเงินหน้างาน — ค่าใช้จ่ายนอกแพ็กเกจที่ต้องเก็บกันตรงนั้น (ค่าเข้าอุทยานของ
 * ชาวต่างชาติ ค่าลูกหาบ ค่าเช่าเต็นท์เพิ่ม ทิปไกด์ท้องถิ่น)
 *
 * เดิมสตาฟเดินไล่เก็บเงินสดแล้วจดเอง — การ์ดนี้บอกแต่ละคนว่าต้องจ่ายเท่าไร มี QR
 * พร้อมเพย์ของยอดตัวเอง และให้สตาฟติ๊กว่าใครจ่ายแล้ว (โอนหรือเงินสดก็ได้)
 *
 * ยอดอิงผู้โดยสาร (TripRosterService) — คนที่จองให้ทั้งกลุ่มเห็นยอดรวมของกลุ่ม
 * และจ่ายทีเดียว ส่วนสตาฟเลือกได้ว่าเก็บทุกคนหรือเฉพาะบางคน (เช่น ชาวต่างชาติ)
 */
class ChatCollectionService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private PromptPayService $promptPay,
    ) {}

    /**
     * @param  array<int, int>|null  $passengerIds  null = ทุกคนในรอบ
     */
    public function open(
        User $staff,
        TripSchedule $schedule,
        string $title,
        ?string $note,
        float $amount,
        ?array $passengerIds,
        ?string $promptPayId,
        ?string $payeeName,
    ): ChatCollection {
        $roster = $this->roster->passengers($schedule);
        $ids = $this->validIds($roster, $passengerIds);

        if ($ids->isEmpty()) {
            throw new \Exception($roster->isEmpty() ? 'ยังไม่มีผู้เดินทางในรอบนี้' : 'เลือกอย่างน้อย 1 คน');
        }

        $collection = DB::transaction(function () use ($staff, $schedule, $title, $note, $amount, $ids, $promptPayId, $payeeName) {
            $collection = ChatCollection::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'title' => $title,
                'note' => $note ?: null,
                'amount' => $amount,
                'promptpay_id' => $promptPayId,
                'payee_name' => $payeeName ?: null,
            ]);

            foreach ($ids as $id) {
                ChatCollectionDue::create([
                    'collection_id' => $collection->id,
                    'passenger_id' => $id,
                    'amount' => $amount,
                ]);
            }

            $who = $ids->count() === $this->roster->passengers($schedule)->count()
                ? 'ทุกคน'
                : "{$ids->count()} คน";

            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "💰 เก็บเงิน: {$title} — คนละ ฿{$this->baht($amount)} ({$who}) ดูยอดของคุณและสแกนจ่ายในการ์ดนี้",
            ]);

            $collection->update(['message_id' => $message->id]);

            return $collection;
        });

        $message = $collection->message()->with(['user', 'replyTo.user', 'reactions', 'collection.dues'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $collection->fresh('dues');
    }

    /**
     * กำหนดว่าใครต้องจ่าย — คนที่เพิ่มเข้ามาได้ยอดตั้งต้น คนที่เอาออกหายจากรายการ
     * (คนที่จ่ายแล้วเอาออกไม่ได้ ต้องยกเลิกการจ่ายก่อน กันยอดที่เก็บไปแล้วหายเงียบ)
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setPayers(ChatCollection $collection, array $passengerIds): ChatCollection
    {
        $roster = $this->roster->passengers($collection->schedule);
        $ids = $this->validIds($roster, $passengerIds);

        if ($ids->isEmpty()) {
            throw new \Exception('ต้องเหลืออย่างน้อย 1 คน');
        }

        $removingPaid = ChatCollectionDue::where('collection_id', $collection->id)
            ->whereNotIn('passenger_id', $ids)
            ->whereNotNull('paid_at')
            ->exists();
        if ($removingPaid) {
            throw new \Exception('มีคนที่จ่ายแล้วอยู่ในรายชื่อที่เอาออก — ยกเลิกสถานะจ่ายของเขาก่อน');
        }

        DB::transaction(function () use ($collection, $ids) {
            ChatCollectionDue::where('collection_id', $collection->id)->whereNotIn('passenger_id', $ids)->delete();

            foreach ($ids as $id) {
                ChatCollectionDue::firstOrCreate(
                    ['collection_id' => $collection->id, 'passenger_id' => $id],
                    ['amount' => $collection->amount],
                );
            }
        });

        return $this->broadcastUpdate($collection);
    }

    /** ลูกทริปกด "โอนแล้ว" (หรือถอน) — ทุกคนที่ตัวเองดูแลที่ยังไม่ได้ยืนยัน */
    public function claim(User $user, ChatCollection $collection, bool $claimed): ChatCollection
    {
        if ($collection->isClosed()) {
            throw new \Exception('ทีมงานปิดยอดแล้ว ทักน้องสตาฟในแชทได้เลยครับ');
        }

        $mine = $this->roster->passengerIdsFor($collection->schedule, $user->id);
        $dues = ChatCollectionDue::where('collection_id', $collection->id)
            ->whereIn('passenger_id', $mine)
            ->whereNull('paid_at');

        if (! (clone $dues)->exists()) {
            throw new \Exception('ไม่มียอดค้างของคุณในรายการนี้');
        }

        $dues->update(['paid_claimed_at' => $claimed ? now() : null]);

        return $this->broadcastUpdate($collection);
    }

    /**
     * สตาฟยืนยัน/ยกเลิกว่าได้เงินแล้ว (โอนหรือเงินสด) — ทีละคนหรือทั้งกลุ่ม
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setPaid(User $staff, ChatCollection $collection, array $passengerIds, bool $paid): ChatCollection
    {
        $dues = ChatCollectionDue::where('collection_id', $collection->id)
            ->whereIn('passenger_id', collect($passengerIds)->map(fn ($id) => (int) $id));

        if (! (clone $dues)->exists()) {
            throw new \Exception('ไม่พบรายชื่อนี้ในรายการเก็บเงิน');
        }

        $dues->update($paid
            ? ['paid_at' => now(), 'paid_marked_by_id' => $staff->id]
            : ['paid_at' => null, 'paid_claimed_at' => null, 'paid_marked_by_id' => null]);

        return $this->broadcastUpdate($collection);
    }

    /** ปิดยอด — สรุปเข้าห้องว่าเก็บได้เท่าไร */
    public function close(ChatCollection $collection): ChatCollection
    {
        if ($collection->isClosed()) {
            return $collection->fresh('dues');
        }

        $collection->update(['closed_at' => now()]);
        $fresh = $this->broadcastUpdate($collection);
        $p = $this->present($fresh);

        $this->chatService->postSystem(
            $collection->schedule,
            "💰 ปิดยอด “{$collection->title}” — เก็บได้ ฿{$this->baht($p['collected'])} จาก ฿{$this->baht($p['total'])}"
                .($p['unpaid_count'] > 0 ? " (ยังค้าง {$p['unpaid_count']} คน)" : ' ครบทุกคน 🎉'),
        );

        return $fresh;
    }

    public function reopen(ChatCollection $collection): ChatCollection
    {
        $collection->update(['closed_at' => null]);

        return $this->broadcastUpdate($collection);
    }

    /**
     * payload การ์ด — ไม่ผูกผู้ดู: แต่ละคนหายอดของตัวเองจาก user_id และได้ QR
     * ของยอดที่ยังค้างจาก payloads[user_id]
     *
     * @return array<string, mixed>
     */
    public function present(ChatCollection $collection): array
    {
        $collection->loadMissing('dues');
        $roster = $this->roster->passengers($collection->schedule)->keyBy('passenger_id');

        $dues = $collection->dues
            ->filter(fn ($d) => $roster->has($d->passenger_id))
            ->map(fn (ChatCollectionDue $d) => [
                'passenger_id' => (int) $d->passenger_id,
                'name' => $roster[$d->passenger_id]['name'],
                'user_id' => $roster[$d->passenger_id]['user_id'],
                'amount' => (float) $d->amount,
                'status' => $d->status(),
            ])->values();

        $total = (float) $dues->sum('amount');
        $collected = (float) $dues->where('status', 'paid')->sum('amount');

        // QR ต่อบัญชี = ผลรวมยอดที่ยังไม่ได้ยืนยันของทุกคนที่บัญชีนั้นดูแล
        $payloads = [];
        if ($collection->promptpay_id && ! $collection->isClosed()) {
            foreach ($dues->where('status', '!=', 'paid')->groupBy('user_id') as $userId => $owed) {
                $sum = (float) $owed->sum('amount');
                if ($userId && $sum > 0) {
                    $payloads[(string) $userId] = $this->promptPay->buildPayload($collection->promptpay_id, $sum);
                }
            }
        }

        return [
            'id' => $collection->id,
            'title' => $collection->title,
            'note' => $collection->note,
            'amount' => (float) $collection->amount,
            'promptpay_id' => $collection->promptpay_id,
            'payee_name' => $collection->payee_name,
            'is_closed' => $collection->isClosed(),
            'total' => round($total, 2),
            'collected' => round($collected, 2),
            'outstanding' => round($total - $collected, 2),
            'due_count' => $dues->count(),
            'paid_count' => $dues->where('status', 'paid')->count(),
            'unpaid_count' => $dues->where('status', '!=', 'paid')->count(),
            'dues' => $dues->all(),
            'payloads' => (object) $payloads,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $roster
     * @param  array<int, int>|null  $passengerIds
     * @return Collection<int, int>
     */
    private function validIds(Collection $roster, ?array $passengerIds): Collection
    {
        $valid = $roster->pluck('passenger_id');

        if ($passengerIds === null) {
            return $valid->values();
        }

        return collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $valid->contains($id))
            ->values();
    }

    private function broadcastUpdate(ChatCollection $collection): ChatCollection
    {
        $fresh = $collection->fresh('dues');

        broadcast(new ChatCollectionUpdated(
            (int) $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }

    private function baht(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2);
    }
}
