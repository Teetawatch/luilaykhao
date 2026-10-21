<?php

namespace App\Services;

use App\Models\ChatCollection;
use App\Models\ChatFoodRound;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatRestStop;
use App\Models\ChatWellnessCheck;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "รอคุณอยู่" — สิ่งที่ผู้ใช้คนนี้ยังต้องกดในห้องแชท รวมไว้ในแถบเดียวบนหัวห้อง
 *
 * ห้องที่ค้างอ่าน 80 ข้อความทำให้การ์ดที่ต้องตอบ (โหวต สั่งอาหาร จ่ายเงิน กลับขึ้นรถ…)
 * จมอยู่กลางแชท ที่นี่ไม่สร้างสถานะใหม่ — อ่านจากการ์ดแต่ละแบบด้วยกติกาเดียวกับ
 * ที่การ์ดใช้ตัดสินว่า "ของฉัน" ยังไม่ได้ทำ (รายชื่อผู้โดยสารผูกบัญชีผู้ดูแลผ่าน
 * TripRosterService เจ้าของใบจองจึงเห็นงานของทั้งกลุ่ม)
 *
 * ข้อความที่ถูกลบ/ซ่อนพาการ์ดหายไปด้วย จึงไม่ถูกนับ
 */
class ChatTodoService
{
    /** เรียงตามความเร่งด่วนเมื่อไม่มีเวลาปิดกำกับ (น้อย = บนสุด) */
    private const PRIORITY = [
        'rest_stop' => 0,
        'wakeup' => 0,
        'meetup' => 1,
        'vote' => 1,
        'wellness' => 2,
        'ack' => 3,
        'food_order' => 4,
        'collection' => 5,
        'food_pay' => 5,
        'home' => 6,
        'poll' => 7,
    ];

    public function __construct(
        private ChatAckService $acks,
        private ChatPollService $polls,
        private ChatFoodOrderService $food,
        private ChatCollectionService $collections,
        private ChatRestStopService $restStops,
        private ChatWellnessService $wellness,
    ) {}

    /**
     * @return array<int, array{kind: string, message_id: int, title: string, detail: string, due_at: ?string, count: int}>
     */
    public function forUser(User $user, TripSchedule $schedule): array
    {
        $items = collect()
            ->merge($this->acks($user, $schedule))
            ->merge($this->polls($user, $schedule))
            ->merge($this->foodRounds($user, $schedule))
            ->merge($this->collections($user, $schedule))
            ->merge($this->restStops($user, $schedule))
            ->merge($this->wellnessChecks($user, $schedule));

        // มีเวลาปิดมาก่อน (ใกล้สุดก่อน) แล้วค่อยตามลำดับความสำคัญ แล้วข้อความใหม่ก่อน
        return $items
            ->sort(function (array $a, array $b) {
                if (($a['due_at'] === null) !== ($b['due_at'] === null)) {
                    return $a['due_at'] === null ? 1 : -1;
                }
                if ($a['due_at'] !== null && $a['due_at'] !== $b['due_at']) {
                    return strcmp($a['due_at'], $b['due_at']);
                }
                $byPriority = (self::PRIORITY[$a['kind']] ?? 9) <=> (self::PRIORITY[$b['kind']] ?? 9);

                return $byPriority !== 0 ? $byPriority : $b['message_id'] <=> $a['message_id'];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function acks(User $user, TripSchedule $schedule): array
    {
        return $this->acks->pendingFor($user, $schedule)
            ->map(fn (ChatMessage $m) => $this->item(
                'ack',
                $m->id,
                'กดรับทราบประกาศ',
                Str::limit(trim(strtok((string) $m->body, "\n") ?: ''), 80) ?: 'ประกาศจากทีมงาน',
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function polls(User $user, TripSchedule $schedule): array
    {
        return ChatPoll::where('schedule_id', $schedule->id)
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', now()))
            ->whereDoesntHave('votes', fn ($q) => $q->where('user_id', $user->id))
            ->whereHas('message', $this->liveMessage(...))
            ->get()
            ->map(fn (ChatPoll $p) => $this->item(
                $p->isVote() ? 'vote' : 'poll',
                (int) $p->message_id,
                $p->isVote() ? 'โหวตตัดสิน' : 'ตอบโพล',
                Str::limit($p->question, 80),
                $p->closes_at,
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function foodRounds(User $user, TripSchedule $schedule): array
    {
        $out = [];
        $rounds = ChatFoodRound::where('schedule_id', $schedule->id)
            ->whereHas('message', $this->liveMessage(...))
            ->with('orders.user')
            ->get();

        foreach ($rounds as $round) {
            $mine = $round->orders->where('user_id', $user->id);

            // ยังเปิดรับ แต่ยังไม่สั่ง (และไม่ได้กด "รอบนี้ไม่สั่ง")
            if (! $round->isClosed() && $mine->isEmpty()) {
                $out[] = $this->item('food_order', (int) $round->message_id, 'สั่งอาหาร', Str::limit($round->title, 80), $round->closes_at);
            }

            // หารบิลแล้ว — ยอดที่ฉันยังไม่ได้จ่าย (ค่าอาหาร / ส่วนต่างเมื่อราคาขึ้น)
            if ($round->billed_at !== null && $mine->isNotEmpty()) {
                $owed = collect($this->food->present($round)['orders'])
                    ->where('user_id', $user->id)
                    ->whereIn('pay_status', ['unpaid', 'short'])
                    ->sum(fn ($o) => (float) ($o['balance'] ?? 0));

                if ($owed > 0) {
                    $out[] = $this->item('food_pay', (int) $round->message_id, 'จ่ายค่าอาหาร', '฿'.$this->money($owed).' · '.Str::limit($round->title, 50));
                }
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function collections(User $user, TripSchedule $schedule): array
    {
        return ChatCollection::where('schedule_id', $schedule->id)
            ->whereNull('closed_at')
            ->whereHas('message', $this->liveMessage(...))
            ->get()
            ->map(function (ChatCollection $c) use ($user) {
                $owed = collect($this->collections->present($c)['dues'])
                    ->where('user_id', $user->id)
                    ->where('status', 'unpaid');

                return $owed->isEmpty() ? null : $this->item(
                    'collection',
                    (int) $c->message_id,
                    'จ่าย'.Str::limit($c->title, 40),
                    '฿'.$this->money((float) $owed->sum('amount')).($owed->count() > 1 ? " · {$owed->count()} คน" : ''),
                    null,
                    $owed->count(),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function restStops(User $user, TripSchedule $schedule): array
    {
        $now = now();

        return ChatRestStop::where('schedule_id', $schedule->id)
            ->whereNull('departed_at')
            ->whereHas('message', $this->liveMessage(...))
            ->with('boardings')
            ->get()
            ->map(function (ChatRestStop $stop) use ($user, $now) {
                $at = $stop->return_at;

                // กดได้จริงตอนนี้เท่านั้น — นัดรวมพลพรุ่งนี้ยังกด "มาถึงแล้ว" ไม่ได้
                $actionable = match (true) {
                    $stop->isMeetup() => $at !== null && $now->gte($at->copy()->subHours(ChatRestStop::MEETUP_ARRIVE_WINDOW_HOURS)),
                    $stop->isWakeup() => $at !== null && $now->gte($at->copy()->subMinutes(ChatRestStop::WAKEUP_EARLY_MINUTES)),
                    default => true,
                };
                if (! $actionable) {
                    return null;
                }

                $waiting = collect($this->restStops->present($stop)['passengers'])
                    ->where('user_id', $user->id)
                    ->where('boarded', false);
                if ($waiting->isEmpty()) {
                    return null;
                }

                [$kind, $title] = match (true) {
                    $stop->isMeetup() => ['meetup', 'กด "มาถึงแล้ว" ที่จุดนัด'],
                    $stop->isWakeup() => ['wakeup', 'กด "ตื่นแล้ว"'],
                    $stop->isHome() => ['home', 'กด "ถึงบ้านแล้ว"'],
                    default => ['rest_stop', 'กด "ขึ้นรถแล้ว"'],
                };

                $detail = collect([
                    $stop->place,
                    $waiting->count() > 1 ? 'ยังไม่กด '.$waiting->count().' คน' : null,
                ])->filter()->implode(' · ');

                // เช็คถึงบ้าน: return_at คือเวลาปิดการ์ดเอง ไม่ใช่เส้นตาย
                return $this->item($kind, (int) $stop->message_id, $title, $detail, $stop->isHome() ? null : $at, $waiting->count());
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function wellnessChecks(User $user, TripSchedule $schedule): array
    {
        return ChatWellnessCheck::where('schedule_id', $schedule->id)
            ->whereNull('closed_at')
            ->whereHas('message', $this->liveMessage(...))
            ->with('answers')
            ->get()
            ->map(function (ChatWellnessCheck $check) use ($user) {
                $unanswered = collect($this->wellness->present($check, $user->id)['mine'] ?? [])
                    ->whereNull('status');

                return $unanswered->isEmpty() ? null : $this->item(
                    'wellness',
                    (int) $check->message_id,
                    'ตอบเช็คอาการ',
                    Str::limit($check->label ?: 'ยังไหวไหม', 60),
                    null,
                    $unanswered->count(),
                );
            })
            ->filter()
            ->values()
            ->all();
    }

    /** ข้อความที่ยังอยู่ในห้อง (ไม่ถูกลบ/ซ่อน) */
    private function liveMessage($query): void
    {
        $query->where('is_deleted', false)->whereNull('hidden_at');
    }

    /**
     * @return array{kind: string, message_id: int, title: string, detail: string, due_at: ?string, count: int}
     */
    private function item(string $kind, int $messageId, string $title, string $detail, ?Carbon $due = null, int $count = 1): array
    {
        return [
            'kind' => $kind,
            'message_id' => $messageId,
            'title' => $title,
            'detail' => $detail,
            'due_at' => $due?->toISOString(),
            'count' => $count,
        ];
    }

    private function money(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2);
    }
}
