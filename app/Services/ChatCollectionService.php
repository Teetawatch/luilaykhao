<?php

namespace App\Services;

use App\Events\ChatCollectionUpdated;
use App\Events\ChatMessageSent;
use App\Models\ChatCollection;
use App\Models\ChatCollectionDue;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * เก็บเงินหน้างาน — ค่าใช้จ่ายนอกแพ็กเกจที่ต้องเก็บกันตรงนั้น (ค่าเข้าอุทยานของ
 * ชาวต่างชาติ ค่าลูกหาบ ค่าเช่าเต็นท์เพิ่ม ทิปไกด์ท้องถิ่น)
 *
 * เดิมสตาฟเดินไล่เก็บเงินสดแล้วจดเอง — การ์ดนี้บอกแต่ละคนว่าต้องจ่ายเท่าไร มี QR
 * พร้อมเพย์ของยอดตัวเอง และให้สตาฟติ๊กว่าใครจ่ายแล้ว (โอนหรือเงินสดก็ได้)
 *
 * ยอดอิงผู้โดยสาร (TripRosterService) — คนที่จองให้ทั้งกลุ่มเห็นยอดรวมของกลุ่ม
 * และจ่ายทีเดียว ส่วนสตาฟเลือกได้ว่าเก็บทุกคนหรือเฉพาะบางคน (เช่น ชาวต่างชาติ)
 *
 * กันโกง: แจ้งโอนต้องแนบสลิป (ไฟล์อยู่บนดิสก์ส่วนตัว ดูได้เฉพาะทีมงานกับคนจ่าย
 * ผ่าน [self::slipUrl]) และสตาฟยืนยัน "โอน" ได้เฉพาะยอดที่มีสลิป — เงินสดต้องกด
 * แยก ระบบจำวิธีรับและสตาฟคนที่รับไว้ ยอดปิดท้ายจึงแยกได้ว่าเงินสดอยู่กับใคร
 */
class ChatCollectionService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private PromptPayService $promptPay,
        private FcmService $fcm,
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
     * เก็บเงินที่ยอดแต่ละคนไม่เท่ากัน — มาจากระบบอื่นที่คิดยอดให้แล้ว (ค่าลูกหาบตาม
     * น้ำหนักกระเป๋า) การ์ดเลิกพูดว่า "คนละ ฿x" และแก้รายชื่อจากการ์ดนี้ไม่ได้
     *
     * @param  array<int, float>  $amounts  passenger_id => ยอด (> 0)
     */
    public function openWithAmounts(
        User $staff,
        TripSchedule $schedule,
        string $title,
        ?string $note,
        array $amounts,
        ?string $promptPayId,
        ?string $payeeName,
        string $source,
    ): ChatCollection {
        $roster = $this->roster->passengers($schedule);
        $amounts = collect($amounts)
            ->mapWithKeys(fn ($amount, $id) => [(int) $id => round((float) $amount, 2)])
            ->filter(fn ($amount, $id) => $amount > 0 && $roster->contains('passenger_id', $id));

        if ($amounts->isEmpty()) {
            throw new \Exception('ไม่มียอดที่ต้องเก็บ');
        }

        $collection = DB::transaction(function () use ($staff, $schedule, $title, $note, $amounts, $promptPayId, $payeeName, $source) {
            $collection = ChatCollection::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'title' => $title,
                'note' => $note ?: null,
                // ยอดต่อคนไม่เท่ากัน — ไม่มี "ยอดตั้งต้น" ให้ใช้
                'amount' => 0,
                'promptpay_id' => $promptPayId,
                'payee_name' => $payeeName ?: null,
                'source' => $source,
            ]);

            foreach ($amounts as $id => $amount) {
                ChatCollectionDue::create([
                    'collection_id' => $collection->id,
                    'passenger_id' => $id,
                    'amount' => $amount,
                ]);
            }

            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "💰 เก็บเงิน: {$title} — รวม ฿{$this->baht((float) $amounts->sum())} ({$amounts->count()} คน ยอดไม่เท่ากัน) ดูยอดของคุณและสแกนจ่ายในการ์ดนี้",
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
     * ปรับยอดตามระบบต้นทาง (ชั่งกระเป๋าใหม่) — เฉพาะคนที่ยังไม่จ่าย คนที่จ่ายแล้วไม่แตะ
     * (ระบบต้นทางต้องกันไม่ให้แก้ยอดของคนที่จ่ายแล้วเอง) ยอด 0 = เอาออกจากรายการ
     *
     * @param  array<int, float>  $amounts  passenger_id => ยอด
     */
    public function syncAmounts(ChatCollection $collection, array $amounts): ChatCollection
    {
        $amounts = collect($amounts)->mapWithKeys(fn ($amount, $id) => [(int) $id => round((float) $amount, 2)]);

        DB::transaction(function () use ($collection, $amounts) {
            $dues = ChatCollectionDue::where('collection_id', $collection->id)->get()->keyBy('passenger_id');

            foreach ($dues as $passengerId => $due) {
                if ($due->paid_at !== null) {
                    continue;
                }
                $amount = $amounts->get((int) $passengerId, 0.0);
                if ($amount <= 0) {
                    $due->delete();
                } elseif ((float) $due->amount !== $amount) {
                    // ยอดเปลี่ยน = สลิปเดิม (ถ้ามี) ไม่ตรงยอดใหม่แล้ว ให้แจ้งโอนใหม่
                    $old = $due->slip_path;
                    $due->update(['amount' => $amount, 'paid_claimed_at' => null, 'slip_path' => null]);
                    $this->deleteUnusedSlips([$old]);
                }
            }

            foreach ($amounts as $passengerId => $amount) {
                if ($amount > 0 && ! $dues->has($passengerId)) {
                    ChatCollectionDue::create([
                        'collection_id' => $collection->id,
                        'passenger_id' => $passengerId,
                        'amount' => $amount,
                    ]);
                }
            }
        });

        return $this->broadcastUpdate($collection);
    }

    /** ผู้โดยสารคนนี้จ่ายรายการนี้ไปแล้วหรือยัง */
    public function isPaid(ChatCollection $collection, int $passengerId): bool
    {
        return ChatCollectionDue::where('collection_id', $collection->id)
            ->where('passenger_id', $passengerId)
            ->whereNotNull('paid_at')
            ->exists();
    }

    /**
     * กำหนดว่าใครต้องจ่าย — คนที่เพิ่มเข้ามาได้ยอดตั้งต้น คนที่เอาออกหายจากรายการ
     * (คนที่จ่ายแล้วเอาออกไม่ได้ ต้องยกเลิกการจ่ายก่อน กันยอดที่เก็บไปแล้วหายเงียบ)
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setPayers(ChatCollection $collection, array $passengerIds): ChatCollection
    {
        if ($collection->amountsVary()) {
            throw new \Exception('ยอดของรายการนี้มาจากการชั่งกระเป๋า — แก้ที่การ์ดฝากลูกหาบแทนครับ');
        }

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

    /**
     * ลูกทริปแจ้งโอนพร้อมสลิป (หรือถอน) — ทุกคนที่ตัวเองดูแลที่ยังไม่ได้ยืนยัน
     * สลิปใบเดียวผูกทุกแถวนั้น เพราะ QR ของบัญชีคือยอดรวมของทั้งกลุ่ม
     */
    public function claim(User $user, ChatCollection $collection, bool $claimed, ?UploadedFile $slip = null): ChatCollection
    {
        if ($collection->isClosed()) {
            throw new \Exception('ทีมงานปิดยอดแล้ว ทักน้องสตาฟในแชทได้เลยครับ');
        }

        if ($claimed && $slip === null) {
            throw new \Exception('แนบสลิปการโอนก่อนแจ้งทีมงานครับ (ถ้าไม่เห็นปุ่มแนบสลิป อัปเดตแอปก่อนนะครับ)');
        }

        $mine = $this->roster->passengerIdsFor($collection->schedule, $user->id);
        $dues = ChatCollectionDue::where('collection_id', $collection->id)
            ->whereIn('passenger_id', $mine)
            ->whereNull('paid_at');

        $oldSlips = (clone $dues)->whereNotNull('slip_path')->pluck('slip_path')->all();
        if (! (clone $dues)->exists()) {
            throw new \Exception('ไม่มียอดค้างของคุณในรายการนี้');
        }

        $path = $claimed ? $this->storeSlip($slip) : null;

        $dues->update([
            'paid_claimed_at' => $claimed ? now() : null,
            'slip_path' => $path,
        ]);
        $this->deleteUnusedSlips($oldSlips);

        return $this->broadcastUpdate($collection);
    }

    /**
     * ลิงก์ชั่วคราวดูสลิปของผู้โดยสารคนนี้ — ผู้เรียกต้องเช็กสิทธิ์ก่อน (ทีมงาน หรือ
     * บัญชีที่ดูแลผู้โดยสารคนนั้น)
     */
    public function slipUrl(ChatCollection $collection, int $passengerId): ?string
    {
        $path = ChatCollectionDue::where('collection_id', $collection->id)
            ->where('passenger_id', $passengerId)
            ->value('slip_path');

        return MediaDisk::slipUrl($path);
    }

    /** ลบไฟล์สลิปของทุกรายการในรอบนี้ — ใช้ตอนล้างห้องแชทที่จบทริปแล้ว */
    public function purgeSlips(int $scheduleId): void
    {
        $paths = ChatCollectionDue::whereIn('collection_id', ChatCollection::where('schedule_id', $scheduleId)->select('id'))
            ->whereNotNull('slip_path')
            ->pluck('slip_path')
            ->unique()
            ->all();

        if ($paths !== []) {
            Storage::disk(MediaDisk::slipDisk())->delete($paths);
        }
    }

    /**
     * สตาฟยืนยัน/ยกเลิกว่าได้เงินแล้ว — ทีละคนหรือทั้งกลุ่ม
     *
     * โอน ([ChatCollectionDue::METHOD_TRANSFER], ค่าตั้งต้นของแอปรุ่นก่อนที่ไม่ส่ง
     * method) ยืนยันได้เฉพาะคนที่แนบสลิปแล้ว ส่วนเงินสดกดได้เสมอแต่ถูกบันทึกว่าเป็น
     * เงินสดที่สตาฟคนนี้รับ ยกเลิก (paid=false) = สลิปไม่ผ่านด้วย — ล้างการแจ้งโอนกับ
     * สลิป ให้ลูกทริปแนบใหม่
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setPaid(
        User $staff,
        ChatCollection $collection,
        array $passengerIds,
        bool $paid,
        string $method = ChatCollectionDue::METHOD_TRANSFER,
        ?string $reason = null,
    ): ChatCollection {
        $dues = ChatCollectionDue::where('collection_id', $collection->id)
            ->whereIn('passenger_id', collect($passengerIds)->map(fn ($id) => (int) $id));

        if (! (clone $dues)->exists()) {
            throw new \Exception('ไม่พบรายชื่อนี้ในรายการเก็บเงิน');
        }

        if (! $paid) {
            $oldSlips = (clone $dues)->whereNotNull('slip_path')->pluck('slip_path')->all();
            // แจ้งโอนไว้แต่ยังไม่ได้ยืนยัน = ตีกลับสลิป (คนที่ยืนยันแล้วแล้วยกเลิก = แก้กดผิดของทีมงาน ไม่ต้องเด้ง)
            $rejected = (clone $dues)->whereNull('paid_at')->whereNotNull('paid_claimed_at')->pluck('passenger_id');
            $dues->update([
                'paid_at' => null,
                'paid_method' => null,
                'paid_claimed_at' => null,
                'slip_path' => null,
                'paid_marked_by_id' => null,
            ]);
            $this->deleteUnusedSlips($oldSlips);

            $fresh = $this->broadcastUpdate($collection);
            if ($rejected->isNotEmpty()) {
                $this->notifyRejected($fresh, $rejected, $reason);
            }

            return $fresh;
        }

        if ($method === ChatCollectionDue::METHOD_TRANSFER) {
            $missing = (clone $dues)->whereNull('paid_at')->whereNull('slip_path')->pluck('passenger_id');
            if ($missing->isNotEmpty()) {
                $names = $this->roster->passengers($collection->schedule)
                    ->whereIn('passenger_id', $missing->all())
                    ->pluck('name')
                    ->join(', ');

                throw new \Exception("ยังไม่มีสลิปโอนของ {$names} — รอให้แนบสลิปก่อน หรือถ้ารับเป็นเงินสดให้กด \"รับเงินสด\"");
            }
        }

        // คนที่ยืนยันไปแล้วไม่ถูกเขียนทับ — วิธีรับและคนรับต้องเป็นของจริงตอนนั้น
        $dues->whereNull('paid_at')->update([
            'paid_at' => now(),
            'paid_method' => $method,
            'paid_marked_by_id' => $staff->id,
        ]);

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
        $collection->loadMissing('dues.markedBy:id,name,nickname');
        $roster = $this->roster->passengers($collection->schedule)->keyBy('passenger_id');

        $dues = $collection->dues
            ->filter(fn ($d) => $roster->has($d->passenger_id))
            ->map(fn (ChatCollectionDue $d) => [
                'passenger_id' => (int) $d->passenger_id,
                'name' => $roster[$d->passenger_id]['name'],
                'user_id' => $roster[$d->passenger_id]['user_id'],
                'amount' => (float) $d->amount,
                'status' => $d->status(),
                // แค่บอกว่ามีสลิป — ตัวไฟล์ขอผ่าน GET .../slip ที่เช็กสิทธิ์ เพราะ payload นี้
                // กระจายให้ทั้งห้อง
                'has_slip' => $d->slip_path !== null,
                'method' => $d->paid_at !== null ? $d->paid_method : null,
                'marked_by' => $d->paid_at !== null
                    ? ($d->markedBy?->nickname ?: $d->markedBy?->name)
                    : null,
            ])->values();

        $total = (float) $dues->sum('amount');
        $collected = (float) $dues->where('status', 'paid')->sum('amount');
        $collectedCash = (float) $dues->where('status', 'paid')
            ->where('method', ChatCollectionDue::METHOD_CASH)
            ->sum('amount');

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
            // ยอดต่อคนไม่เท่ากัน (ค่าลูกหาบตามน้ำหนัก) — การ์ดไม่พูดว่า "คนละ ฿x"
            'amounts_vary' => $collection->amountsVary(),
            'promptpay_id' => $collection->promptpay_id,
            'payee_name' => $collection->payee_name,
            'is_closed' => $collection->isClosed(),
            'total' => round($total, 2),
            'collected' => round($collected, 2),
            // ที่เหลือจาก collected คือโอน (รวมรายการเก่าที่ยืนยันก่อนมีช่องวิธีรับ)
            'collected_cash' => round($collectedCash, 2),
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

    /**
     * บอกคนดูแลว่าสลิปยังไม่ผ่าน ต้องแนบใหม่ — ไม่งั้นเขาเห็นแค่การ์ดกลับเป็น "ยังไม่จ่าย"
     * โดยไม่รู้ว่าเกิดอะไรขึ้น
     *
     * @param  Collection<int, int>  $passengerIds
     */
    private function notifyRejected(ChatCollection $collection, Collection $passengerIds, ?string $reason): void
    {
        $roster = $this->roster->passengers($collection->schedule)->whereIn('passenger_id', $passengerIds->map(fn ($id) => (int) $id)->all());
        $reason = $reason !== null ? (trim($reason) ?: null) : null;

        foreach ($roster->groupBy('user_id') as $userId => $people) {
            if (! $userId) {
                continue;
            }

            try {
                $this->fcm->sendToUser(
                    (int) $userId,
                    "💰 สลิป{$collection->title}ยังไม่ผ่าน",
                    ($reason ? "{$reason} — " : 'ทีมงานยังไม่เห็นยอดเข้า — ')
                        .'แนบสลิปใหม่ในการ์ดเก็บเงินได้เลยครับ (ยอด ฿'.$this->baht((float) $collection->dues()->whereIn('passenger_id', $people->pluck('passenger_id'))->sum('amount')).')',
                    [
                        'type' => 'collection_slip_rejected',
                        'route' => 'chat',
                        'schedule_id' => (string) $collection->schedule_id,
                        'message_id' => (string) $collection->message_id,
                    ],
                    ['android_channel' => 'important_updates'],
                );
            } catch (\Throwable $e) {
                Log::warning('ChatCollectionService: แจ้งสลิปไม่ผ่านไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function storeSlip(UploadedFile $slip): string
    {
        $path = $slip->store('slips/chat-collections/'.date('Y/m'), MediaDisk::slipDisk());
        if (! is_string($path) || $path === '') {
            throw new \Exception('อัปโหลดสลิปไม่สำเร็จ ลองใหม่อีกครั้งครับ');
        }

        return $path;
    }

    /** @param  array<int, string>  $paths */
    private function deleteUnusedSlips(array $paths): void
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return;
        }

        $stillUsed = ChatCollectionDue::whereIn('slip_path', $paths)->pluck('slip_path')->all();
        $unused = array_values(array_diff($paths, $stillUsed));
        if ($unused !== []) {
            Storage::disk(MediaDisk::slipDisk())->delete($unused);
        }
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
