<?php

namespace App\Services;

use App\Models\ChatCollection;
use App\Models\ChatCollectionDue;
use App\Models\ChatFoodRound;
use App\Models\OnsiteMoneyHandover;
use App\Models\OnsiteMoneyReport;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * สรุปยอดหน้างานของรอบ + ส่งมอบเงินให้บริษัท
 *
 * เงินที่เก็บในห้องแชท (เก็บเงินหน้างาน / ค่าลูกหาบ) ไปอยู่กับสตาฟสองทาง: เงินสดที่
 * สตาฟคนนั้นกดรับ และเงินโอนที่เข้าพร้อมเพย์ของสตาฟที่เปิดการ์ด — สรุปนี้บอกว่า
 * "เงินอยู่กับใครเท่าไร" สตาฟกด "ส่งเงินแล้ว" (แช่ยอดตอนนั้น) การเงินกด "ได้รับแล้ว"
 *
 * ค่าอาหาร (หารบิล) แสดงแยกไว้ดูว่าเก็บครบไหม แต่ไม่นับในยอดส่งบริษัท — สตาฟสำรอง
 * จ่ายร้านไปก่อนแล้วเก็บคืน เป็นเงินของสตาฟเอง
 *
 * การ์ดเก็บเงินหายไปพร้อมห้องแชทตอนล้าง (14 วันหลังจบทริป) — [freeze] แช่สรุปไว้ใน
 * onsite_money_reports ก่อนล้าง หลังจากนั้นสรุปอ่านจากที่แช่ไว้
 */
class OnsiteMoneyService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_RECEIVED = 'received';

    /** ส่งแล้วแต่ยอดที่ถือเปลี่ยนไปหลังส่ง (เก็บเพิ่ม/ยกเลิกการจ่าย) — ต้องส่งใหม่ */
    public const STATUS_CHANGED = 'changed';

    /** ยอดต่างกันไม่ถึงนี้ถือว่าเท่ากัน (ทศนิยมสตางค์) */
    private const EPSILON = 0.005;

    public function __construct(
        private TripRosterService $roster,
        private ChatFoodOrderService $food,
    ) {}

    /**
     * สรุปเต็มของรอบ — สดจากการ์ดถ้ายังอยู่ ไม่งั้นจากที่แช่ไว้ + สถานะส่งมอบของแต่ละคน
     *
     * @return array<string, mixed>
     */
    public function summary(TripSchedule $schedule): array
    {
        $base = $this->hasLiveCards($schedule)
            ? $this->live($schedule)
            : (OnsiteMoneyReport::where('schedule_id', $schedule->id)->value('summary') ?? $this->empty());

        $handovers = OnsiteMoneyHandover::with(['user:id,name,nickname', 'receivedBy:id,name,nickname'])
            ->where('schedule_id', $schedule->id)
            ->get()
            ->keyBy('user_id');

        $holders = collect($base['holders'] ?? [])->keyBy('user_id');

        // คนที่เคยส่งแล้วแต่ตอนนี้ไม่ถือเงินแล้ว (ยกเลิกการจ่ายหมด) ยังต้องโผล่ ให้เห็นว่าเปลี่ยน
        foreach ($handovers as $userId => $h) {
            if (! $holders->has($userId)) {
                $holders[$userId] = [
                    'user_id' => (int) $userId,
                    'name' => $h->user?->nickname ?: ($h->user?->name ?? 'ทีมงาน'),
                    'cash' => 0.0,
                    'transfer' => 0.0,
                    'total' => 0.0,
                    'promptpay' => null,
                ];
            }
        }

        $base['holders'] = $holders->map(function (array $holder) use ($handovers) {
            $h = $handovers->get($holder['user_id']);

            return [
                ...$holder,
                'status' => $this->statusOf($holder, $h),
                'handover' => $h ? [
                    'cash' => (float) $h->cash_amount,
                    'transfer' => (float) $h->transfer_amount,
                    'total' => $h->total(),
                    'note' => $h->note,
                    'submitted_at' => $h->submitted_at?->toISOString(),
                    'received_at' => $h->received_at?->toISOString(),
                    'received_by' => $h->receivedBy?->nickname ?: $h->receivedBy?->name,
                    'received_note' => $h->received_note,
                ] : null,
            ];
        })->sortByDesc('total')->values()->all();

        $base['schedule'] = [
            'id' => $schedule->id,
            'trip_title' => $schedule->trip?->title,
            'departure_date' => $schedule->departure_date?->toDateString(),
            'return_date' => ($schedule->return_date ?? $schedule->departure_date)?->toDateString(),
        ];
        $base['is_frozen'] = ! $this->hasLiveCards($schedule) && OnsiteMoneyReport::where('schedule_id', $schedule->id)->exists();
        $base['pending_amount'] = round((float) collect($base['holders'])
            ->where('status', '!=', self::STATUS_RECEIVED)
            ->sum('total'), 2);

        return $base;
    }

    /**
     * สตาฟยืนยันส่งเงินที่ตัวเองถือของรอบนี้ — แช่ยอดตอนนั้นไว้ ส่งซ้ำได้ (ยอดใหม่ ล้างการรับเดิม)
     */
    public function submit(User $staff, TripSchedule $schedule, ?string $note): OnsiteMoneyHandover
    {
        $holder = collect($this->summary($schedule)['holders'])->firstWhere('user_id', $staff->id);

        if (! $holder || $holder['total'] <= 0) {
            throw new \Exception('คุณไม่ได้ถือเงินหน้างานของรอบนี้');
        }
        if ($holder['status'] === self::STATUS_RECEIVED) {
            throw new \Exception('การเงินรับเงินก้อนนี้ไปแล้ว');
        }

        return OnsiteMoneyHandover::updateOrCreate(
            ['schedule_id' => $schedule->id, 'user_id' => $staff->id],
            [
                'cash_amount' => $holder['cash'],
                'transfer_amount' => $holder['transfer'],
                'note' => $note !== null ? (trim($note) ?: null) : null,
                'submitted_at' => now(),
                'received_by_id' => null,
                'received_at' => null,
                'received_note' => null,
            ],
        );
    }

    /**
     * การเงินรับเงินจากสตาฟคนนี้แล้ว — รับได้เฉพาะก้อนที่ส่งแล้วและยอดยังตรง
     */
    public function receive(User $finance, TripSchedule $schedule, int $userId, ?string $note): OnsiteMoneyHandover
    {
        $holder = collect($this->summary($schedule)['holders'])->firstWhere('user_id', $userId);
        $handover = OnsiteMoneyHandover::where('schedule_id', $schedule->id)->where('user_id', $userId)->first();

        if (! $handover || ! $holder) {
            throw new \Exception('สตาฟคนนี้ยังไม่ได้กดส่งเงิน');
        }
        if ($holder['status'] === self::STATUS_CHANGED) {
            throw new \Exception('ยอดที่สตาฟถือเปลี่ยนหลังกดส่ง (฿'.$this->baht($holder['total']).' ไม่ใช่ ฿'.$this->baht($handover->total()).') — ให้สตาฟกดส่งใหม่ก่อน');
        }
        if ($holder['status'] === self::STATUS_RECEIVED) {
            return $handover;
        }

        $handover->update([
            'received_by_id' => $finance->id,
            'received_at' => now(),
            'received_note' => $note !== null ? (trim($note) ?: null) : null,
        ]);

        return $handover->fresh();
    }

    /** ยกเลิกการรับ (กดผิด) */
    public function unreceive(TripSchedule $schedule, int $userId): void
    {
        OnsiteMoneyHandover::where('schedule_id', $schedule->id)
            ->where('user_id', $userId)
            ->update(['received_by_id' => null, 'received_at' => null, 'received_note' => null]);
    }

    /**
     * แช่สรุปของรอบนี้ก่อนห้องแชทถูกล้าง — ไม่มีการ์ดเงินเลยก็ไม่ต้องเก็บ
     */
    public function freeze(TripSchedule $schedule): void
    {
        if (! $this->hasLiveCards($schedule)) {
            return;
        }

        $live = $this->live($schedule);
        if ($live['collections'] === [] && $live['food'] === []) {
            return;
        }

        OnsiteMoneyReport::updateOrCreate(
            ['schedule_id' => $schedule->id],
            ['summary' => $live, 'frozen_at' => now()],
        );
    }

    /**
     * รอบที่มีเงินหน้างาน (การ์ดสด หรือแช่ไว้แล้ว) ในช่วง [days] วันล่าสุด — หน้าการเงิน
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentRounds(int $days = 60): array
    {
        $since = now('Asia/Bangkok')->subDays($days)->toDateString();

        $ids = ChatCollection::query()->select('schedule_id')
            ->union(OnsiteMoneyReport::query()->select('schedule_id'))
            ->union(OnsiteMoneyHandover::query()->select('schedule_id'))
            ->pluck('schedule_id')
            ->unique();

        return TripSchedule::with('trip:id,title')
            ->whereIn('id', $ids)
            ->whereDate('departure_date', '>=', $since)
            ->orderByDesc('departure_date')
            ->get()
            ->map(function (TripSchedule $schedule) {
                $s = $this->summary($schedule);
                $holders = collect($s['holders']);

                return [
                    ...$s['schedule'],
                    'collected' => $s['totals']['collected'],
                    'cash' => $s['totals']['cash'],
                    'transfer' => $s['totals']['transfer'],
                    'outstanding' => $s['totals']['outstanding'],
                    'pending_amount' => $s['pending_amount'],
                    'holder_count' => $holders->count(),
                    'received_count' => $holders->where('status', self::STATUS_RECEIVED)->count(),
                    'is_frozen' => $s['is_frozen'],
                    // ยังมีเงินค้างอยู่กับสตาฟ = ยังไม่จบ
                    'is_settled' => $holders->every(fn ($h) => $h['status'] === self::STATUS_RECEIVED),
                ];
            })
            // รอบที่ไม่มีเงินเข้าเลยและไม่มีใครค้าง ไม่ต้องรก
            ->filter(fn ($r) => $r['collected'] > 0 || $r['outstanding'] > 0 || $r['holder_count'] > 0)
            ->values()
            ->all();
    }

    /**
     * สตาฟที่ยังถือเงินของรอบนี้แต่ยังไม่ได้ส่ง (หรือยอดเปลี่ยนหลังส่ง)
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unsentHolders(TripSchedule $schedule): Collection
    {
        return collect($this->summary($schedule)['holders'])
            ->filter(fn ($h) => $h['total'] > 0 && in_array($h['status'], [self::STATUS_PENDING, self::STATUS_CHANGED], true))
            ->values();
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    private function hasLiveCards(TripSchedule $schedule): bool
    {
        return ChatCollection::where('schedule_id', $schedule->id)->exists()
            || ChatFoodRound::where('schedule_id', $schedule->id)->whereNotNull('billed_at')->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function live(TripSchedule $schedule): array
    {
        $roster = $this->roster->passengers($schedule)->keyBy('passenger_id');
        $collections = ChatCollection::with(['dues', 'dues.markedBy:id,name,nickname'])
            ->where('schedule_id', $schedule->id)
            ->orderBy('id')
            ->get();
        $creators = User::whereIn('id', $collections->pluck('created_by_id')->filter()->unique())
            ->get(['id', 'name', 'nickname'])
            ->keyBy('id');

        /** @var array<int, array<string, mixed>> $holders */
        $holders = [];
        $hold = function (?int $userId, ?User $user, string $kind, float $amount, ?string $promptpay) use (&$holders) {
            if ($amount <= 0) {
                return;
            }
            $key = $userId ?? 0;
            $holders[$key] ??= [
                'user_id' => $userId,
                'name' => $user?->nickname ?: ($user?->name ?? 'ไม่ทราบชื่อ'),
                'cash' => 0.0,
                'transfer' => 0.0,
                'total' => 0.0,
                'promptpay' => null,
            ];
            $holders[$key][$kind] = round($holders[$key][$kind] + $amount, 2);
            $holders[$key]['total'] = round($holders[$key]['total'] + $amount, 2);
            if ($kind === 'transfer' && $promptpay) {
                $holders[$key]['promptpay'] = $promptpay;
            }
        };

        $rows = $collections->map(function (ChatCollection $c) use ($roster, $creators, $hold) {
            $dues = $c->dues->filter(fn (ChatCollectionDue $d) => $roster->has($d->passenger_id));
            $paid = $dues->whereNotNull('paid_at');
            $cash = $paid->where('paid_method', ChatCollectionDue::METHOD_CASH);
            $transfer = $paid->reject(fn ($d) => $d->paid_method === ChatCollectionDue::METHOD_CASH);
            $creator = $c->created_by_id ? $creators->get($c->created_by_id) : null;
            $promptpay = $this->maskPromptPay($c->promptpay_id);

            // เงินสด → คนที่กดรับ, เงินโอน → เจ้าของพร้อมเพย์ของการ์ด (คนเปิดการ์ด)
            foreach ($cash->groupBy('paid_marked_by_id') as $userId => $group) {
                $hold($userId ? (int) $userId : null, $group->first()->markedBy, 'cash', (float) $group->sum('amount'), null);
            }
            $hold($c->created_by_id ? (int) $c->created_by_id : null, $creator, 'transfer', (float) $transfer->sum('amount'), $promptpay);

            $total = (float) $dues->sum('amount');
            $collected = (float) $paid->sum('amount');

            return [
                'id' => $c->id,
                'title' => $c->title,
                'source' => $c->source,
                'is_closed' => $c->isClosed(),
                'receiver' => $creator?->nickname ?: $creator?->name,
                'promptpay' => $promptpay,
                'payee_name' => $c->payee_name,
                'total' => round($total, 2),
                'collected' => round($collected, 2),
                'cash' => round((float) $cash->sum('amount'), 2),
                'transfer' => round((float) $transfer->sum('amount'), 2),
                'outstanding' => round($total - $collected, 2),
                'unpaid' => $dues->whereNull('paid_at')
                    ->map(fn ($d) => [
                        'name' => $roster[$d->passenger_id]['name'],
                        'amount' => (float) $d->amount,
                        'claimed' => $d->paid_claimed_at !== null,
                    ])->values()->all(),
            ];
        })->values();

        $food = ChatFoodRound::where('schedule_id', $schedule->id)
            ->whereNotNull('billed_at')
            ->orderBy('id')
            ->get()
            ->map(function (ChatFoodRound $round) {
                $bill = $this->food->billingFor($round);

                return $bill ? [
                    'id' => $round->id,
                    'title' => $round->title,
                    'payee_name' => $bill['payee_name'],
                    'total' => (float) $bill['total'],
                    'collected' => (float) $bill['collected'],
                    'outstanding' => (float) $bill['outstanding'],
                    'owing_count' => (int) $bill['owing_count'],
                ] : null;
            })
            ->filter()
            ->values();

        return [
            'collections' => $rows->all(),
            'holders' => array_values($holders),
            'food' => $food->all(),
            'totals' => [
                'collected' => round((float) $rows->sum('collected'), 2),
                'cash' => round((float) $rows->sum('cash'), 2),
                'transfer' => round((float) $rows->sum('transfer'), 2),
                'outstanding' => round((float) $rows->sum('outstanding'), 2),
                'food_collected' => round((float) $food->sum('collected'), 2),
                'food_outstanding' => round((float) $food->sum('outstanding'), 2),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $holder
     */
    private function statusOf(array $holder, ?OnsiteMoneyHandover $h): string
    {
        if (! $h) {
            return self::STATUS_PENDING;
        }

        $same = abs((float) $h->cash_amount - $holder['cash']) < self::EPSILON
            && abs((float) $h->transfer_amount - $holder['transfer']) < self::EPSILON;

        if (! $same) {
            return self::STATUS_CHANGED;
        }

        return $h->received_at ? self::STATUS_RECEIVED : self::STATUS_SUBMITTED;
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'collections' => [],
            'holders' => [],
            'food' => [],
            'totals' => ['collected' => 0.0, 'cash' => 0.0, 'transfer' => 0.0, 'outstanding' => 0.0, 'food_collected' => 0.0, 'food_outstanding' => 0.0],
        ];
    }

    /** "081-xxx-5678" — การเงินรู้ว่าเงินเข้าบัญชีไหนโดยไม่โชว์เลขเต็ม */
    private function maskPromptPay(?string $digits): ?string
    {
        if (! $digits) {
            return null;
        }

        return strlen($digits) === 10
            ? substr($digits, 0, 3).'-xxx-'.substr($digits, -4)
            : 'xxxxxxxxx'.substr($digits, -4);
    }

    private function baht(float $amount): string
    {
        return fmod($amount, 1.0) === 0.0 ? number_format($amount) : number_format($amount, 2);
    }
}
