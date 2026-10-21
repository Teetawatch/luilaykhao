<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatPorterUpdated;
use App\Models\ChatCollection;
use App\Models\ChatMessage;
use App\Models\ChatPorterBag;
use App\Models\ChatPorterRound;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ฝากของลูกหาบ
 *
 * ปัญหาจริง: ภูกระดึง/เชียงดาวคิดค่าลูกหาบเป็นกิโล ต้องชั่งกระเป๋า จด คิดเงินทีละคน
 * แล้วตามเก็บเงินอีกรอบ กระเป๋าตอนรับคืนก็ปนกัน — การ์ดนี้ให้ลูกทริปกด "ฝากกระเป๋า"
 * ทีมงานชั่งแล้วได้เลขป้ายต่อใบ ระบบคิดยอดเอง กด "ส่งยอดเก็บเงิน" ได้การ์ดเก็บเงิน
 * (QR + แนบสลิป) ทันที และตอนรับคืนติ๊กทีละใบจนครบ
 *
 * ยอดเงินอยู่ที่ [ChatCollection] (source = porter) ที่เดียว — ชั่งใหม่หลังส่งยอดแล้ว
 * ยอดของคนที่ยังไม่จ่ายตามไปเอง คนที่จ่ายแล้วแก้กระเป๋าไม่ได้จนกว่าจะยกเลิกการจ่าย
 */
class ChatPorterService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private ChatCollectionService $collections,
    ) {}

    public function open(
        User $staff,
        TripSchedule $schedule,
        string $title,
        float $ratePerKg,
        float $minCharge,
        bool $roundUp,
        ?string $promptPayId,
        ?string $payeeName,
    ): ChatPorterRound {
        if ($this->roster->travelling($schedule)->isEmpty()) {
            throw new \Exception('ยังไม่มีผู้เดินทางในรอบนี้');
        }

        $round = DB::transaction(function () use ($staff, $schedule, $title, $ratePerKg, $minCharge, $roundUp, $promptPayId, $payeeName) {
            $round = ChatPorterRound::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'title' => $title,
                'rate_per_kg' => $ratePerKg,
                'min_charge' => $minCharge,
                'round_up' => $roundUp,
                'promptpay_id' => $promptPayId,
                'payee_name' => $payeeName ?: null,
            ]);

            $min = $minCharge > 0 ? " (ขั้นต่ำใบละ ฿{$this->baht($minCharge)})" : '';
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "🎒 {$title} — กก.ละ ฿{$this->baht($ratePerKg)}{$min} ใครจะฝากกด \"ฝากกระเป๋า\" ในการ์ดนี้ "
                    .'ทีมงานชั่งแล้วให้เลขป้ายกระเป๋า เก็บเลขไว้รับคืนนะครับ',
            ]);

            $round->update(['message_id' => $message->id]);

            return $round;
        });

        $message = $round->message()->with(['user', 'replyTo.user', 'reactions', 'porterRound.bags'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $round->fresh('bags');
    }

    /**
     * ลงชื่อฝากกระเป๋า [count] ใบ — ลูกทริปฝากได้เฉพาะคนที่ตัวเองดูแล (ยังไม่ชั่ง)
     * ทีมงานเพิ่มให้ใครก็ได้ และใส่น้ำหนักไปพร้อมกันได้
     */
    public function addBags(User $user, ChatPorterRound $round, int $passengerId, int $count, ?string $note, ?float $weightKg, bool $asStaff): ChatPorterRound
    {
        if ($round->isClosed() && ! $asStaff) {
            throw new \Exception('ปิดรับฝากแล้ว ทักทีมงานในแชทได้เลยครับ');
        }
        if ($count < 1) {
            throw new \Exception('ฝากอย่างน้อย 1 ใบ');
        }
        if ($weightKg !== null && ! $asStaff) {
            throw new \Exception('น้ำหนักให้ทีมงานเป็นคนชั่งนะครับ');
        }

        $this->assertCanTouch($user, $round, $passengerId, $asStaff);
        $this->assertWeight($weightKg);

        DB::transaction(function () use ($user, $round, $passengerId, $count, $note, $weightKg) {
            // เลขป้ายต้องไม่ชน — ล็อกการ์ดไว้ระหว่างหาเลขถัดไป
            ChatPorterRound::whereKey($round->id)->lockForUpdate()->first();

            $mine = ChatPorterBag::where('round_id', $round->id)->where('passenger_id', $passengerId)->count();
            if ($mine + $count > ChatPorterRound::MAX_BAGS_PER_PASSENGER) {
                throw new \Exception('ฝากได้ไม่เกิน '.ChatPorterRound::MAX_BAGS_PER_PASSENGER.' ใบต่อคน');
            }

            $next = (int) ChatPorterBag::where('round_id', $round->id)->max('tag_no') + 1;
            for ($i = 0; $i < $count; $i++) {
                ChatPorterBag::create([
                    'round_id' => $round->id,
                    'passenger_id' => $passengerId,
                    'tag_no' => $next + $i,
                    'weight_kg' => $weightKg,
                    'note' => $note !== null ? (trim($note) ?: null) : null,
                    'created_by_id' => $user->id,
                ]);
            }
        });

        return $this->afterChange($round);
    }

    /** ทีมงานใส่/แก้น้ำหนัก (null = ยังไม่ชั่ง) */
    public function weigh(User $staff, ChatPorterRound $round, int $bagId, ?float $weightKg): ChatPorterRound
    {
        $bag = $this->bag($round, $bagId);
        $this->assertCanTouch($staff, $round, (int) $bag->passenger_id, asStaff: true);
        $this->assertWeight($weightKg);

        $bag->update(['weight_kg' => $weightKg]);

        return $this->afterChange($round);
    }

    /** เอากระเป๋าออก — เจ้าของเอาใบที่ยังไม่ชั่งออกได้ ทีมงานเอาออกได้ทุกใบที่ยังไม่คืน */
    public function removeBag(User $user, ChatPorterRound $round, int $bagId, bool $asStaff): ChatPorterRound
    {
        $bag = $this->bag($round, $bagId);
        $this->assertCanTouch($user, $round, (int) $bag->passenger_id, $asStaff);

        if (! $asStaff && $round->isClosed()) {
            throw new \Exception('ปิดรับฝากแล้ว ทักทีมงานในแชทได้เลยครับ');
        }
        if (! $asStaff && $bag->weight_kg !== null) {
            throw new \Exception('ชั่งแล้ว ให้ทีมงานเป็นคนเอาออกนะครับ');
        }
        if ($bag->returned_at !== null) {
            throw new \Exception('กระเป๋าใบนี้คืนไปแล้ว');
        }

        $bag->delete();

        return $this->afterChange($round);
    }

    /**
     * รับกระเป๋าคืนแล้ว (หรือยกเลิก) — ทีมงานติ๊กได้ทุกใบ เจ้าของติ๊กของตัวเองได้
     * คืนครบทุกใบ = ประกาศในห้องครั้งเดียว
     *
     * @param  array<int, int>  $bagIds
     */
    public function setReturned(User $user, ChatPorterRound $round, array $bagIds, bool $returned, bool $asStaff): ChatPorterRound
    {
        $mine = $this->roster->passengers($round->schedule)->where('user_id', $user->id)->pluck('passenger_id');
        $bags = ChatPorterBag::where('round_id', $round->id)
            ->whereIn('id', collect($bagIds)->map(fn ($id) => (int) $id))
            ->when(! $asStaff, fn ($q) => $q->whereIn('passenger_id', $mine))
            ->get();

        if ($bags->isEmpty()) {
            throw new \Exception($asStaff ? 'ไม่พบกระเป๋าใบนี้' : 'ติ๊กได้เฉพาะกระเป๋าของคุณและคนในกลุ่ม');
        }

        ChatPorterBag::whereIn('id', $bags->pluck('id'))->update($returned
            ? ['returned_at' => now(), 'returned_by_id' => $user->id]
            : ['returned_at' => null, 'returned_by_id' => null]);

        if ($returned) {
            $total = ChatPorterBag::where('round_id', $round->id)->count();
            $back = ChatPorterBag::where('round_id', $round->id)->whereNotNull('returned_at')->count();

            if ($total > 0 && $back >= $total
                && ChatPorterRound::whereKey($round->id)->whereNull('returned_announced_at')->update(['returned_announced_at' => now()]) > 0) {
                $this->chatService->postSystem($round->schedule, "🎒 คืนกระเป๋าครบ {$total} ใบแล้ว เช็กของในกระเป๋าให้ครบด้วยนะครับ");
            }
        }

        return $this->broadcastUpdate($round);
    }

    /**
     * ส่งยอดเข้า "เก็บเงินหน้างาน" — ต้องชั่งครบทุกใบก่อน ส่งได้ครั้งเดียว
     * (ชั่งเพิ่มทีหลังยอดตามไปเองผ่าน [afterChange])
     */
    public function bill(User $staff, ChatPorterRound $round): ChatPorterRound
    {
        if ($round->collection_id) {
            throw new \Exception('ส่งยอดเก็บเงินไปแล้ว ชั่งเพิ่มทีหลังยอดจะอัปเดตเองครับ');
        }

        $bags = ChatPorterBag::where('round_id', $round->id)->get();
        if ($bags->isEmpty()) {
            throw new \Exception('ยังไม่มีใครฝากกระเป๋า');
        }
        $unweighed = $bags->whereNull('weight_kg')->count();
        if ($unweighed > 0) {
            throw new \Exception("ยังไม่ได้ชั่งอีก {$unweighed} ใบ — ชั่งให้ครบก่อนส่งยอด");
        }

        $collection = $this->collections->openWithAmounts(
            $staff,
            $round->schedule,
            "ค่าลูกหาบ · {$round->title}",
            'กก.ละ ฿'.$this->baht((float) $round->rate_per_kg).' — ยอดตามน้ำหนักกระเป๋าของแต่ละคน',
            $this->feesByPassenger($round),
            $round->promptpay_id,
            $round->payee_name,
            ChatCollection::SOURCE_PORTER,
        );

        $round->update(['collection_id' => $collection->id]);

        return $this->broadcastUpdate($round);
    }

    /** ปิด/เปิดรับฝาก — ปิดแล้วลูกทริปลงชื่อ/เอาออกเองไม่ได้ ทีมงานยังชั่ง/แก้ได้ และติ๊กรับคืนได้ */
    public function setClosed(ChatPorterRound $round, bool $closed): ChatPorterRound
    {
        $round->update(['closed_at' => $closed ? ($round->closed_at ?? now()) : null]);

        return $this->broadcastUpdate($round);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ChatPorterRound $round, ?int $viewerId = null): array
    {
        $round->loadMissing('bags');
        $roster = $this->roster->passengers($round->schedule)->keyBy('passenger_id');

        $bags = $round->bags
            ->filter(fn (ChatPorterBag $b) => $roster->has($b->passenger_id))
            ->map(fn (ChatPorterBag $b) => [
                'id' => $b->id,
                'tag_no' => (int) $b->tag_no,
                'passenger_id' => (int) $b->passenger_id,
                'name' => $roster[$b->passenger_id]['name'],
                'user_id' => $roster[$b->passenger_id]['user_id'],
                'weight_kg' => $b->weight_kg,
                'fee' => $round->feeFor($b->weight_kg),
                'note' => $b->note,
                'returned' => $b->returned_at !== null,
            ])->values();

        $collection = $round->collection_id ? ChatCollection::find($round->collection_id) : null;
        $paidIds = $collection
            ? $collection->dues()->whereNotNull('paid_at')->pluck('passenger_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return [
            'id' => $round->id,
            'title' => $round->title,
            'rate_per_kg' => (float) $round->rate_per_kg,
            'min_charge' => (float) $round->min_charge,
            'round_up' => (bool) $round->round_up,
            'is_closed' => $round->isClosed(),
            'collection_message_id' => $collection?->message_id ? (int) $collection->message_id : null,
            'is_billed' => $collection !== null,
            // จ่ายแล้ว = แก้กระเป๋าของคนนี้ไม่ได้ (แอปซ่อนปุ่ม)
            'paid_passenger_ids' => $paidIds,
            'bag_count' => $bags->count(),
            'weighed_count' => $bags->whereNotNull('weight_kg')->count(),
            'returned_count' => $bags->where('returned', true)->count(),
            'total_kg' => round((float) $bags->sum('weight_kg'), 2),
            'total_fee' => round((float) $bags->sum('fee'), 2),
            'bags' => $bags->all(),
            // คนที่บัญชีนี้ฝากแทนได้ — มีเฉพาะเมื่อรู้ว่าใครดู (broadcast ไม่มี แอปคงค่าเดิม)
            ...($viewerId !== null ? [
                'mine' => $roster->where('user_id', $viewerId)
                    ->map(fn ($p) => ['id' => $p['passenger_id'], 'name' => $p['name']])
                    ->values()->all(),
            ] : []),
        ];
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    /** @return array<int, float> passenger_id => ค่าฝากรวม */
    private function feesByPassenger(ChatPorterRound $round): array
    {
        return ChatPorterBag::where('round_id', $round->id)
            ->whereNotNull('weight_kg')
            ->get()
            ->groupBy('passenger_id')
            ->map(fn (Collection $bags) => round((float) $bags->sum(fn (ChatPorterBag $b) => $round->feeFor($b->weight_kg)), 2))
            ->all();
    }

    /** หลังกระเป๋าเปลี่ยน — ส่งยอดไปแล้ว ยอดของคนที่ยังไม่จ่ายตามไปด้วย */
    private function afterChange(ChatPorterRound $round): ChatPorterRound
    {
        $round->refresh();
        if ($round->collection_id && ($collection = ChatCollection::find($round->collection_id))) {
            $this->collections->syncAmounts($collection, $this->feesByPassenger($round));
        }

        return $this->broadcastUpdate($round);
    }

    private function assertCanTouch(User $user, ChatPorterRound $round, int $passengerId, bool $asStaff): void
    {
        $roster = $this->roster->passengers($round->schedule);
        $allowed = $asStaff
            ? $roster->pluck('passenger_id')
            : $roster->where('user_id', $user->id)->pluck('passenger_id');

        if (! $allowed->contains($passengerId)) {
            throw new \Exception($asStaff ? 'ไม่พบผู้เดินทางในรอบนี้' : 'ฝากได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }

        $collection = $round->collection_id ? ChatCollection::find($round->collection_id) : null;
        if ($collection && $this->collections->isPaid($collection, $passengerId)) {
            throw new \Exception('คนนี้จ่ายค่าลูกหาบไปแล้ว — ยกเลิกสถานะจ่ายในการ์ดเก็บเงินก่อนแก้กระเป๋า');
        }
    }

    private function assertWeight(?float $weightKg): void
    {
        if ($weightKg !== null && ($weightKg <= 0 || $weightKg > ChatPorterRound::MAX_WEIGHT_KG)) {
            throw new \Exception('น้ำหนักต้องมากกว่า 0 และไม่เกิน '.ChatPorterRound::MAX_WEIGHT_KG.' กก.');
        }
    }

    private function bag(ChatPorterRound $round, int $bagId): ChatPorterBag
    {
        $bag = ChatPorterBag::where('round_id', $round->id)->find($bagId);
        if (! $bag) {
            throw new \Exception('ไม่พบกระเป๋าใบนี้');
        }

        return $bag;
    }

    private function broadcastUpdate(ChatPorterRound $round): ChatPorterRound
    {
        $fresh = $round->fresh('bags');

        broadcast(new ChatPorterUpdated(
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
