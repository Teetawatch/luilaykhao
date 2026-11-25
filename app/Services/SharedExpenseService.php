<?php

namespace App\Services;

use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\TripSharedExpense;
use App\Models\TripSharedExpenseShare;
use App\Models\TripSharedSettlement;
use App\Models\User;
use App\Support\AppLocale;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * หารค่าใช้จ่ายกับเพื่อนร่วมทริป — "ใครออกค่าอะไรไป ใครต้องโอนให้ใครเท่าไร"
 *
 * ต่างจาก ChatCollectionService / บิลอาหาร ซึ่งเป็นทีมงานเก็บเงินลูกทริป ตรงนี้
 * ลูกทริปหารกันเอง ทีมงานไม่เกี่ยว (ค่าข้าว ค่าเรือ ค่าแท็กซี่ ของฝากที่ซื้อรวม)
 *
 * กติกาหลัก
 * - อิงผู้โดยสาร (TripRosterService) ไม่ใช่บัญชี เพื่อนที่ไม่มีแอปก็ร่วมหารได้
 *   คนที่จองให้เป็นคน "ดูแล" ยอดแทน เห็นและบันทึกการจ่ายคืนแทนได้
 * - แต่ละคนเห็นเฉพาะรายการที่คนที่ตัวเองดูแลเกี่ยวข้อง (ออกเงินหรือร่วมหาร)
 *   ทริปจอยมีคนแปลกหน้า เรื่องเงินของกลุ่มเพื่อนไม่ควรโผล่ให้ทั้งรอบเห็น
 * - คิดยอด "ระหว่างคู่" เท่านั้น ไม่ลดทอนข้ามคน (A ติด B, B ติด C ไม่กลายเป็น
 *   A โอนให้ C) — ไม่งั้นคนที่ไม่เคยหารอะไรกันถูกจับคู่ให้โอนเงินหากัน
 * - เงินคิดเป็นสตางค์ (จำนวนเต็ม) ทุกขั้น หารไม่ลงตัว เศษสตางค์ตกที่คนออกเงิน
 *   (ถ้าร่วมหารด้วย) แล้วไล่ตามลำดับ ยอดรวมของส่วนแบ่งจึงเท่ายอดจ่ายเสมอ
 * - บันทึกได้ตั้งแต่ปิดส่งต่อที่นั่ง (ก่อนรถออก 3 ชม.) — รายชื่อนิ่งแล้ว ที่นั่งที่
 *   มีหนี้ผูกอยู่จะไม่ถูกส่งต่อให้คนอื่นรับหนี้ไปแทน — ถึงหลังกลับ 30 วัน
 *   หลังจากนั้นดูได้อย่างเดียว
 */
class SharedExpenseService
{
    public const TIMEZONE = 'Asia/Bangkok';

    public const MAX_AMOUNT = 100000;

    public const MAX_PEOPLE = 60;

    /** ปิดบันทึกหลังวันกลับกี่วัน — พอให้กลับบ้านไปเคลียร์เงินกันทัน */
    public const EDITABLE_DAYS_AFTER_RETURN = 30;

    public function __construct(
        private TripRosterService $roster,
        private PromptPayService $promptPay,
    ) {}

    // ── ช่วงเวลา ────────────────────────────────────────────────────────────

    /**
     * เวลาเปิดบันทึก เป็นเวลาไทยในรูปเดียวกับ departs_at (ตัวเลขเวลาไทยที่ติดป้าย UTC)
     * — ตรงกับเส้นตายส่งต่อที่นั่ง (SeatHandoverService::deadline)
     */
    public function opensAt(TripSchedule $schedule): ?Carbon
    {
        if ($schedule->departs_at) {
            return $schedule->departs_at->copy()->subHours(SeatHandoverService::CLOSE_HOURS_BEFORE_DEPARTURE);
        }

        return $schedule->departure_date?->copy()->startOfDay();
    }

    public function closesAt(TripSchedule $schedule): ?Carbon
    {
        $last = $schedule->return_date ?? $schedule->departure_date;

        return $last?->copy()->endOfDay()->addDays(self::EDITABLE_DAYS_AFTER_RETURN);
    }

    /** upcoming = ยังไม่ถึงเวลา · open = บันทึกได้ · closed = ดูได้อย่างเดียว */
    public function windowStatus(TripSchedule $schedule): string
    {
        $opens = $this->opensAt($schedule);
        $closes = $this->closesAt($schedule);
        if (! $opens || ! $closes) {
            return 'closed';
        }

        $now = $this->nowThai();

        return match (true) {
            $now->lt($opens) => 'upcoming',
            $now->gt($closes) => 'closed',
            default => 'open',
        };
    }

    // ── สิทธิ์ ─────────────────────────────────────────────────────────────

    /**
     * ผู้โดยสารทุกคนของรอบ (รวมคนที่แจ้งไม่ไป) keyed by passenger_id
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function people(TripSchedule $schedule): Collection
    {
        return $this->roster->passengers($schedule)->keyBy('passenger_id');
    }

    /** @return Collection<int, int> ผู้โดยสารที่บัญชีนี้ดูแล */
    private function mine(Collection $people, User $user): Collection
    {
        return $people->where('user_id', $user->id)->keys()->map(fn ($id) => (int) $id)->values();
    }

    public function canUse(User $user, TripSchedule $schedule): bool
    {
        return $this->mine($this->people($schedule), $user)->isNotEmpty();
    }

    // ── อ่าน ───────────────────────────────────────────────────────────────

    /**
     * ทุกอย่างที่หน้าจอต้องใช้ในคำตอบเดียว — ทุก mutation คืนก้อนนี้ด้วย
     *
     * @return array<string, mixed>
     */
    public function present(User $user, TripSchedule $schedule): array
    {
        $people = $this->people($schedule);
        $mine = $this->mine($people, $user);
        if ($mine->isEmpty()) {
            throw new \Exception('หารค่าใช้จ่ายได้เฉพาะผู้เดินทางในรอบนี้');
        }

        $expenses = TripSharedExpense::where('schedule_id', $schedule->id)
            ->with('shares')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $settlements = TripSharedSettlement::where('schedule_id', $schedule->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $directory = $this->directory($people, $expenses, $settlements);
        $isMine = fn (int $pid) => $mine->contains($pid);
        $status = $this->windowStatus($schedule);
        $open = $status === 'open';

        $visibleExpenses = $expenses->filter(fn (TripSharedExpense $e) => $this->involves($e, $mine) || $e->created_by_id === $user->id);
        $visibleSettlements = $settlements->filter(fn (TripSharedSettlement $s) => $isMine((int) $s->from_passenger_id) || $isMine((int) $s->to_passenger_id));

        $person = fn (int $pid) => [
            'passenger_id' => $pid,
            'name' => $directory[$pid]['name'] ?? 'ผู้โดยสาร',
            'is_mine' => $isMine($pid),
        ];

        $expenseRows = $visibleExpenses->map(function (TripSharedExpense $e) use ($person, $isMine, $user, $open, $mine) {
            $myShare = $e->shares->filter(fn ($s) => $isMine((int) $s->passenger_id))->sum(fn ($s) => $this->satang($s->amount));

            return [
                'id' => $e->id,
                'title' => $e->title,
                'category' => $e->category,
                'amount' => $this->baht($this->satang($e->amount)),
                'note' => $e->note,
                'split_mode' => $e->split_mode,
                'payer' => $person((int) $e->payer_passenger_id),
                'shares' => $e->shares->map(fn (TripSharedExpenseShare $s) => $person((int) $s->passenger_id) + [
                    'amount' => $this->baht($this->satang($s->amount)),
                ])->values()->all(),
                'my_share' => $this->baht($myShare),
                'created_by_me' => $e->created_by_id === $user->id,
                'can_edit' => $open && $this->canEdit($e, $user, $mine),
                'created_at' => $e->created_at?->toIso8601String(),
            ];
        })->values();

        $settlementRows = $visibleSettlements->map(fn (TripSharedSettlement $s) => [
            'id' => $s->id,
            'from' => $person((int) $s->from_passenger_id),
            'to' => $person((int) $s->to_passenger_id),
            'amount' => $this->baht($this->satang($s->amount)),
            'method' => $s->method,
            'recorded_by_me' => $s->recorded_by_id === $user->id,
            'can_delete' => $open,
            'created_at' => $s->created_at?->toIso8601String(),
        ])->values();

        $balances = $this->balancesFor($mine, $expenses, $settlements, $directory, $person, $user, $open);

        $iOwe = $balances->filter(fn ($b) => ! $b['internal'] && $b['from']['is_mine'])->sum('satang');
        $owedToMe = $balances->filter(fn ($b) => ! $b['internal'] && $b['to']['is_mine'])->sum('satang');
        $mySpend = $expenses->flatMap->shares
            ->filter(fn ($s) => $isMine((int) $s->passenger_id))
            ->sum(fn ($s) => $this->satang($s->amount));

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->tr('title'),
            ],
            'window' => $this->presentWindow($schedule, $status),
            'me' => [
                'passenger_ids' => $mine->all(),
                'promptpay_id' => $user->receive_promptpay_id,
            ],
            'people' => $this->peopleFor($people, $directory, $mine, $schedule),
            'categories' => TripSharedExpense::CATEGORIES,
            'expenses' => $expenseRows->all(),
            'settlements' => $settlementRows->all(),
            'balances' => $balances->map(fn ($b) => collect($b)->except('satang')->all())->values()->all(),
            'totals' => [
                'i_owe' => $this->baht((int) $iOwe),
                'owed_to_me' => $this->baht((int) $owedToMe),
                'my_spend' => $this->baht((int) $mySpend),
                'expense_count' => $expenseRows->count(),
            ],
            'limits' => [
                'max_amount' => self::MAX_AMOUNT,
                'max_people' => self::MAX_PEOPLE,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentWindow(TripSchedule $schedule, string $status): array
    {
        $opens = $this->opensAt($schedule);
        $closes = $this->closesAt($schedule);

        $opensLabel = null;
        if ($opens) {
            $opensLabel = $schedule->departs_at
                ? AppLocale::shortDateTime($opens).(AppLocale::isEnglish() ? '' : ' น.')
                : AppLocale::shortDate($opens);
        }

        return [
            'status' => $status,
            'opens_at' => $opens?->format('Y-m-d H:i:s'),
            'opens_label' => $opensLabel,
            'closes_label' => $closes ? AppLocale::shortDate($closes) : null,
            'editable_days_after_return' => self::EDITABLE_DAYS_AFTER_RETURN,
        ];
    }

    /**
     * ตัวเลือกในฟอร์ม — คนที่ไปจริง (ตัดคนที่แจ้งไม่ไป) เรียง: ของฉัน → ใบจองเดียวกัน → คนอื่น
     *
     * @return array<int, array<string, mixed>>
     */
    private function peopleFor(Collection $people, Collection $directory, Collection $mine, TripSchedule $schedule): array
    {
        $myBookings = $people->only($mine->all())->pluck('booking_id')->unique();
        $travelling = $this->roster->travelling($schedule)->pluck('passenger_id')->map(fn ($id) => (int) $id);

        return $people
            ->filter(fn ($p) => $travelling->contains((int) $p['passenger_id']))
            ->map(function ($p) use ($mine, $myBookings, $directory) {
                $pid = (int) $p['passenger_id'];

                return [
                    'passenger_id' => $pid,
                    'name' => $directory[$pid]['name'],
                    'is_mine' => $mine->contains($pid),
                    'same_booking' => $myBookings->contains($p['booking_id']),
                    'has_app' => $p['user_id'] !== null,
                ];
            })
            ->sortBy(fn ($p) => [$p['is_mine'] ? 0 : 1, $p['same_booking'] ? 0 : 1, $p['passenger_id']])
            ->values()
            ->all();
    }

    /**
     * ยอดระหว่างคู่ที่คนที่ฉันดูแลเกี่ยวข้อง — บวก = from ติดเงิน to
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function balancesFor(
        Collection $mine,
        Collection $expenses,
        Collection $settlements,
        Collection $directory,
        \Closure $person,
        User $user,
        bool $open,
    ): Collection {
        $net = $this->pairNet($expenses, $settlements);
        $promptPays = $this->promptPaysOf($directory, $net);

        $rows = collect();
        foreach ($net as $key => $amount) {
            if ($amount === 0) {
                continue;
            }
            [$a, $b] = array_map('intval', explode(':', $key));
            // บวก = a ติด b · ลบ = b ติด a
            [$from, $to] = $amount > 0 ? [$a, $b] : [$b, $a];
            $owed = abs($amount);

            if (! $mine->contains($from) && ! $mine->contains($to)) {
                continue;
            }

            $internal = $mine->contains($from) && $mine->contains($to);
            $toUser = $directory[$to]['user_id'] ?? null;
            $promptPay = null;
            if ($mine->contains($from) && ! $internal && $toUser && $toUser !== $user->id && ($promptPays[$toUser] ?? null)) {
                $promptPay = [
                    'id' => $promptPays[$toUser],
                    'payload' => $this->promptPay->buildPayload($promptPays[$toUser], $owed / 100),
                ];
            }

            $rows->push([
                'from' => $person($from),
                'to' => $person($to) + ['has_app' => $toUser !== null],
                'amount' => $this->baht($owed),
                'satang' => $owed,
                'internal' => $internal,
                'promptpay' => $promptPay,
                'can_settle' => $open,
            ]);
        }

        // ที่ฉันต้องจ่ายขึ้นก่อน แล้วค่อยที่คนอื่นติดฉัน ยอดมากก่อน
        return $rows->sortBy(fn ($r) => [$r['internal'] ? 2 : ($r['from']['is_mine'] ? 0 : 1), -$r['satang']])->values();
    }

    /**
     * ยอดสุทธิของทุกคู่ (สตางค์) คีย์ "เลขน้อย:เลขมาก" บวก = คนเลขน้อยติดคนเลขมาก
     *
     * @return array<string, int>
     */
    public function pairNet(Collection $expenses, Collection $settlements): array
    {
        $net = [];
        $add = function (int $debtor, int $creditor, int $satang) use (&$net) {
            if ($debtor === $creditor || $satang === 0) {
                return;
            }
            $key = min($debtor, $creditor).':'.max($debtor, $creditor);
            $net[$key] = ($net[$key] ?? 0) + ($debtor < $creditor ? $satang : -$satang);
        };

        foreach ($expenses as $expense) {
            foreach ($expense->shares as $share) {
                $add((int) $share->passenger_id, (int) $expense->payer_passenger_id, $this->satang($share->amount));
            }
        }

        // จ่ายคืน = ลูกหนี้ติดน้อยลง (เทียบเท่าเจ้าหนี้ติดลูกหนี้กลับ)
        foreach ($settlements as $settlement) {
            $add((int) $settlement->to_passenger_id, (int) $settlement->from_passenger_id, $this->satang($settlement->amount));
        }

        return $net;
    }

    /** ยอดที่ from ติด to ตอนนี้ (สตางค์) — ติดลบ = to ติด from */
    private function owed(int $scheduleId, int $from, int $to): int
    {
        $expenses = TripSharedExpense::where('schedule_id', $scheduleId)
            ->whereIn('payer_passenger_id', [$from, $to])
            ->with('shares')
            ->get();
        $settlements = TripSharedSettlement::where('schedule_id', $scheduleId)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('from_passenger_id', $from)->where('to_passenger_id', $to))
                ->orWhere(fn ($w) => $w->where('from_passenger_id', $to)->where('to_passenger_id', $from)))
            ->get();

        $net = $this->pairNet($expenses, $settlements)[min($from, $to).':'.max($from, $to)] ?? 0;

        return $from < $to ? $net : -$net;
    }

    /**
     * ชื่อ + คนดูแลของทุกผู้โดยสารที่ถูกอ้างถึง — คนที่หลุดจากรายชื่อรอบไปแล้ว
     * (ใบจองถูกยกเลิก) ยังต้องมีชื่อในรายการเก่า แค่ไม่มีใครในแอปดูแลแทน
     *
     * @return Collection<int, array{name: string, user_id: ?int}>
     */
    private function directory(Collection $people, Collection $expenses, Collection $settlements): Collection
    {
        $dir = $people->map(fn ($p) => ['name' => $p['name'], 'user_id' => $p['user_id']]);

        $referenced = collect()
            ->merge($expenses->pluck('payer_passenger_id'))
            ->merge($expenses->flatMap->shares->pluck('passenger_id'))
            ->merge($settlements->pluck('from_passenger_id'))
            ->merge($settlements->pluck('to_passenger_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $dir->has($id));

        if ($referenced->isNotEmpty()) {
            BookingPassenger::whereIn('id', $referenced->all())->get(['id', 'name', 'nickname'])
                ->each(function ($p) use ($dir) {
                    $dir->put((int) $p->id, ['name' => $this->roster->shortName($p->nickname, $p->name), 'user_id' => null]);
                });
        }

        return $dir;
    }

    /**
     * พร้อมเพย์ของคนดูแลฝั่งเจ้าหนี้ — โหลดเฉพาะบัญชีที่มีคนติดเงิน
     *
     * @param  array<string, int>  $net
     * @return array<int, string>
     */
    private function promptPaysOf(Collection $directory, array $net): array
    {
        $userIds = collect(array_keys($net))
            ->flatMap(fn ($key) => explode(':', $key))
            ->map(fn ($pid) => $directory[(int) $pid]['user_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return [];
        }

        return User::whereIn('id', $userIds->all())->get(['id', 'receive_promptpay_id'])
            ->filter(fn (User $u) => filled($u->receive_promptpay_id))
            ->mapWithKeys(fn (User $u) => [$u->id => (string) $u->receive_promptpay_id])
            ->all();
    }

    private function involves(TripSharedExpense $expense, Collection $mine): bool
    {
        return $mine->contains((int) $expense->payer_passenger_id)
            || $expense->shares->contains(fn ($s) => $mine->contains((int) $s->passenger_id));
    }

    /** แก้/ลบได้: คนบันทึก หรือคนดูแลคนที่ออกเงิน */
    private function canEdit(TripSharedExpense $expense, User $user, Collection $mine): bool
    {
        return $expense->created_by_id === $user->id || $mine->contains((int) $expense->payer_passenger_id);
    }

    // ── เขียน ──────────────────────────────────────────────────────────────

    /**
     * @param  array{title: string, category?: ?string, amount: float|int|string, payer_passenger_id: int, split_mode: string, passenger_ids?: ?array, shares?: ?array, note?: ?string}  $input
     */
    public function create(User $user, TripSchedule $schedule, array $input): TripSharedExpense
    {
        $expense = DB::transaction(function () use ($user, $schedule, $input) {
            $this->lockRound($schedule);
            [$people, $mine] = $this->guardWrite($user, $schedule);
            [$fields, $shares] = $this->normalise($input, $people, $mine);

            $expense = TripSharedExpense::create($fields + [
                'schedule_id' => $schedule->id,
                'created_by_id' => $user->id,
            ]);
            $this->writeShares($expense, $shares);

            return $expense->load('shares');
        });

        $this->notifyExpense($user, $schedule, $expense, 'added');

        return $expense;
    }

    public function update(User $user, TripSchedule $schedule, int $expenseId, array $input): TripSharedExpense
    {
        $expense = DB::transaction(function () use ($user, $schedule, $expenseId, $input) {
            $this->lockRound($schedule);
            [$people, $mine] = $this->guardWrite($user, $schedule);
            $expense = $this->findExpense($schedule, $expenseId);
            if (! $this->canEdit($expense, $user, $mine)) {
                throw new \Exception('แก้ไขได้เฉพาะคนที่บันทึกหรือคนที่ออกเงิน');
            }

            [$fields, $shares] = $this->normalise($input, $people, $mine);
            $expense->update($fields);
            $expense->shares()->delete();
            $this->writeShares($expense, $shares);

            return $expense->load('shares');
        });

        $this->notifyExpense($user, $schedule, $expense, 'changed');

        return $expense;
    }

    public function delete(User $user, TripSchedule $schedule, int $expenseId): void
    {
        $expense = DB::transaction(function () use ($user, $schedule, $expenseId) {
            $this->lockRound($schedule);
            [, $mine] = $this->guardWrite($user, $schedule);
            $expense = $this->findExpense($schedule, $expenseId);
            if (! $this->canEdit($expense, $user, $mine)) {
                throw new \Exception('ลบได้เฉพาะคนที่บันทึกหรือคนที่ออกเงิน');
            }

            // เก็บสำเนาไว้แจ้งคนที่เกี่ยวข้องหลังลบ
            $copy = $expense->replicate();
            $copy->id = $expense->id;
            $copy->setRelation('shares', $expense->shares);
            $expense->shares()->delete();
            $expense->delete();

            return $copy;
        });

        $this->notifyExpense($user, $schedule, $expense, 'deleted');
    }

    public function settle(User $user, TripSchedule $schedule, int $from, int $to, float|int|string $amount, string $method): TripSharedSettlement
    {
        $satang = $this->satang($amount);

        $settlement = DB::transaction(function () use ($user, $schedule, $from, $to, $satang, $method) {
            $this->lockRound($schedule);
            [, $mine] = $this->guardWrite($user, $schedule);

            if ($from === $to) {
                throw new \Exception('เลือกคนจ่ายกับคนรับให้เป็นคนละคน');
            }
            if (! $mine->contains($from) && ! $mine->contains($to)) {
                throw new \Exception('บันทึกการจ่ายคืนได้เฉพาะยอดที่คุณเกี่ยวข้อง');
            }
            if ($satang <= 0) {
                throw new \Exception('ระบุยอดที่จ่ายคืน');
            }

            $owed = $this->owed($schedule->id, $from, $to);
            if ($owed <= 0) {
                throw new \Exception('ไม่มียอดค้างระหว่างสองคนนี้แล้ว');
            }
            if ($satang > $owed) {
                throw new \Exception(__('ยอดเกินที่ค้างอยู่ (ค้าง ฿:amount)', ['amount' => $this->bahtLabel($owed)]));
            }

            return TripSharedSettlement::create([
                'schedule_id' => $schedule->id,
                'from_passenger_id' => $from,
                'to_passenger_id' => $to,
                'amount' => $satang / 100,
                'method' => $method === TripSharedSettlement::METHOD_CASH ? TripSharedSettlement::METHOD_CASH : TripSharedSettlement::METHOD_TRANSFER,
                'recorded_by_id' => $user->id,
            ]);
        });

        $this->notifySettlement($user, $schedule, $settlement, false);

        return $settlement;
    }

    public function unsettle(User $user, TripSchedule $schedule, int $settlementId): void
    {
        $settlement = DB::transaction(function () use ($user, $schedule, $settlementId) {
            $this->lockRound($schedule);
            [, $mine] = $this->guardWrite($user, $schedule);

            $settlement = TripSharedSettlement::where('schedule_id', $schedule->id)->find($settlementId);
            if (! $settlement) {
                throw new \Exception('ไม่พบรายการจ่ายคืนนี้');
            }
            if (! $mine->contains((int) $settlement->from_passenger_id) && ! $mine->contains((int) $settlement->to_passenger_id)) {
                throw new \Exception('ยกเลิกได้เฉพาะรายการที่คุณเกี่ยวข้อง');
            }

            $copy = $settlement->replicate();
            $copy->id = $settlement->id;
            $settlement->delete();

            return $copy;
        });

        $this->notifySettlement($user, $schedule, $settlement, true);
    }

    /** เลขพร้อมเพย์ที่ใช้รับเงินคืน — null = เอาออก */
    public function setPromptPay(User $user, ?string $digits): void
    {
        $user->forceFill(['receive_promptpay_id' => $digits])->save();
    }

    /**
     * ตรวจและเรียบเรียงข้อมูลจากฟอร์ม
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>} [fields, passenger_id => satang]
     */
    private function normalise(array $input, Collection $people, Collection $mine): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new \Exception('ตั้งชื่อรายการก่อน เช่น ค่าเรือ ค่าข้าวเย็น');
        }

        $category = in_array($input['category'] ?? null, TripSharedExpense::CATEGORIES, true) ? $input['category'] : 'other';
        $total = $this->satang($input['amount'] ?? 0);
        if ($total <= 0) {
            throw new \Exception('ระบุยอดที่จ่าย');
        }
        if ($total > self::MAX_AMOUNT * 100) {
            throw new \Exception(__('ยอดต่อรายการต้องไม่เกิน ฿:amount', ['amount' => number_format(self::MAX_AMOUNT)]));
        }

        $payer = (int) ($input['payer_passenger_id'] ?? 0);
        if (! $people->has($payer)) {
            throw new \Exception('ไม่พบคนที่ออกเงินในรายชื่อทริปนี้');
        }

        $mode = ($input['split_mode'] ?? TripSharedExpense::SPLIT_EQUAL) === TripSharedExpense::SPLIT_CUSTOM
            ? TripSharedExpense::SPLIT_CUSTOM
            : TripSharedExpense::SPLIT_EQUAL;

        $shares = $mode === TripSharedExpense::SPLIT_CUSTOM
            ? $this->customShares($input['shares'] ?? [], $total)
            : $this->equalShares($input['passenger_ids'] ?? [], $total, $payer);

        foreach (array_keys($shares) as $pid) {
            if (! $people->has($pid)) {
                throw new \Exception('มีคนที่ไม่ได้อยู่ในรายชื่อทริปนี้');
            }
        }
        if (count($shares) > self::MAX_PEOPLE) {
            throw new \Exception(__('หารได้ไม่เกิน :max คนต่อรายการ', ['max' => self::MAX_PEOPLE]));
        }
        if (array_keys($shares) === [$payer] || $shares === []) {
            throw new \Exception('เลือกเพื่อนที่ร่วมหารอย่างน้อย 1 คน');
        }

        // ต้องเกี่ยวข้องเอง ไม่งั้นบันทึกหนี้ให้คนอื่นแล้วตัวเองมองไม่เห็นรายการนั้นอีก
        $involved = $mine->contains($payer) || collect(array_keys($shares))->contains(fn ($pid) => $mine->contains($pid));
        if (! $involved) {
            throw new \Exception('บันทึกได้เฉพาะรายการที่คุณออกเงินหรือร่วมหารด้วย');
        }

        $note = trim((string) ($input['note'] ?? ''));

        return [[
            'title' => mb_substr($title, 0, 60),
            'category' => $category,
            'amount' => $total / 100,
            'payer_passenger_id' => $payer,
            'split_mode' => $mode,
            'note' => $note === '' ? null : mb_substr($note, 0, 200),
        ], $shares];
    }

    /**
     * หารเท่ากันเป็นสตางค์ — เศษตกที่คนออกเงินก่อน (ถ้าร่วมหาร) แล้วไล่ตามลำดับที่เลือก
     *
     * @return array<int, int>
     */
    public function equalShares(array $passengerIds, int $total, int $payer): array
    {
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->filter(fn ($id) => $id > 0)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        // คนออกเงินขึ้นหัวแถว เศษสตางค์จะได้ตกที่เขา ไม่ใช่ให้เพื่อนจ่ายเกิน
        if ($ids->contains($payer)) {
            $ids = $ids->reject(fn ($id) => $id === $payer)->prepend($payer)->values();
        }

        $n = $ids->count();
        $base = intdiv($total, $n);
        $remainder = $total - $base * $n;

        $shares = [];
        foreach ($ids as $i => $id) {
            $shares[$id] = $base + ($i < $remainder ? 1 : 0);
        }

        return $shares;
    }

    /** @return array<int, int> */
    private function customShares(array $rows, int $total): array
    {
        $shares = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $pid = (int) ($row['passenger_id'] ?? 0);
            $amount = $this->satang($row['amount'] ?? 0);
            if ($pid <= 0 || $amount <= 0) {
                continue;
            }
            if (isset($shares[$pid])) {
                throw new \Exception('มีชื่อซ้ำในรายการหาร');
            }
            $shares[$pid] = $amount;
        }

        if ($shares !== [] && array_sum($shares) !== $total) {
            $diff = $total - array_sum($shares);
            throw new \Exception($diff > 0
                ? __('ยอดของแต่ละคนรวมแล้วยังขาดอีก ฿:amount', ['amount' => $this->bahtLabel($diff)])
                : __('ยอดของแต่ละคนรวมแล้วเกินอีก ฿:amount', ['amount' => $this->bahtLabel(-$diff)]));
        }

        return $shares;
    }

    /** @param  array<int, int>  $shares */
    private function writeShares(TripSharedExpense $expense, array $shares): void
    {
        foreach ($shares as $pid => $satang) {
            TripSharedExpenseShare::create([
                'expense_id' => $expense->id,
                'passenger_id' => $pid,
                'amount' => $satang / 100,
            ]);
        }
    }

    /** @return array{0: Collection, 1: Collection<int, int>} [people, mine] */
    private function guardWrite(User $user, TripSchedule $schedule): array
    {
        $people = $this->people($schedule);
        $mine = $this->mine($people, $user);
        if ($mine->isEmpty()) {
            throw new \Exception('หารค่าใช้จ่ายได้เฉพาะผู้เดินทางในรอบนี้');
        }

        $status = $this->windowStatus($schedule);
        if ($status === 'upcoming') {
            $opens = $this->presentWindow($schedule, $status)['opens_label'];
            throw new \Exception(__('เริ่มบันทึกได้ตั้งแต่ :time', ['time' => $opens]));
        }
        if ($status === 'closed') {
            throw new \Exception(__('ทริปนี้ปิดบันทึกค่าใช้จ่ายแล้ว (หลังกลับ :days วัน)', ['days' => self::EDITABLE_DAYS_AFTER_RETURN]));
        }

        return [$people, $mine];
    }

    /** ล็อกรอบไว้ระหว่างเขียน — กดจ่ายคืนซ้ำสองครั้งพร้อมกันต้องไม่ผ่านทั้งคู่ */
    private function lockRound(TripSchedule $schedule): void
    {
        TripSchedule::whereKey($schedule->id)->lockForUpdate()->first(['id']);
    }

    private function findExpense(TripSchedule $schedule, int $expenseId): TripSharedExpense
    {
        $expense = TripSharedExpense::where('schedule_id', $schedule->id)->with('shares')->find($expenseId);
        if (! $expense) {
            throw new \Exception('ไม่พบรายการนี้ อาจถูกลบไปแล้ว');
        }

        return $expense;
    }

    // ── แจ้งเตือน ──────────────────────────────────────────────────────────

    private function notifyExpense(User $actor, TripSchedule $schedule, TripSharedExpense $expense, string $event): void
    {
        try {
            $people = $this->people($schedule);
            $actorName = $this->actorName($actor);
            $payerName = $people[(int) $expense->payer_passenger_id]['name'] ?? 'เพื่อน';
            $amount = $this->bahtLabel($this->satang($expense->amount));

            $byUser = [];
            foreach ($expense->shares as $share) {
                $userId = $people[(int) $share->passenger_id]['user_id'] ?? null;
                if ($userId && $userId !== $actor->id) {
                    $byUser[$userId] = ($byUser[$userId] ?? 0) + $this->satang($share->amount);
                }
            }
            $payerUser = $people[(int) $expense->payer_passenger_id]['user_id'] ?? null;
            if ($payerUser && $payerUser !== $actor->id) {
                $byUser[$payerUser] ??= 0;
            }

            foreach ($byUser as $userId => $myShare) {
                $owner = User::find($userId);
                if (! $owner) {
                    continue;
                }

                [$title, $body] = AppLocale::withUser($owner, fn () => match ($event) {
                    'added' => [
                        '💸 '.__(':name บันทึกค่า :title', ['name' => $actorName, 'title' => $expense->title]),
                        $myShare > 0
                            ? __('ส่วนของคุณ ฿:share จากยอด ฿:amount · :payer ออกให้ก่อน', ['share' => $this->bahtLabel($myShare), 'amount' => $amount, 'payer' => $payerName])
                            : __(':payer ออกให้ก่อน ฿:amount — ดูว่าใครต้องโอนคืนเท่าไรในแอป', ['payer' => $payerName, 'amount' => $amount]),
                    ],
                    'changed' => [
                        '💸 '.__(':name แก้รายการ :title', ['name' => $actorName, 'title' => $expense->title]),
                        $myShare > 0
                            ? __('ส่วนของคุณตอนนี้ ฿:share จากยอด ฿:amount', ['share' => $this->bahtLabel($myShare), 'amount' => $amount])
                            : __('ยอดรวมตอนนี้ ฿:amount — ยอดที่ต้องโอนคืนอัปเดตแล้ว', ['amount' => $amount]),
                    ],
                    default => [
                        '💸 '.__(':name ลบรายการ :title', ['name' => $actorName, 'title' => $expense->title]),
                        __('ยอด ฿:amount ไม่นับรวมในการหารแล้ว', ['amount' => $amount]),
                    ],
                });

                SmartNotification::send((int) $userId, $event === 'added' ? 'trip_expense_added' : 'trip_expense_changed', $title, $body, [
                    'route' => 'shared_expenses',
                    'schedule_id' => (string) $schedule->id,
                    'expense_id' => (string) $expense->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SharedExpenseService: แจ้งเตือนรายการไม่สำเร็จ', ['expense_id' => $expense->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifySettlement(User $actor, TripSchedule $schedule, TripSharedSettlement $settlement, bool $removed): void
    {
        try {
            $people = $this->people($schedule);
            $fromName = $people[(int) $settlement->from_passenger_id]['name'] ?? 'เพื่อน';
            $toName = $people[(int) $settlement->to_passenger_id]['name'] ?? 'เพื่อน';
            $amount = $this->bahtLabel($this->satang($settlement->amount));
            $actorName = $this->actorName($actor);

            $userIds = collect([
                $people[(int) $settlement->from_passenger_id]['user_id'] ?? null,
                $people[(int) $settlement->to_passenger_id]['user_id'] ?? null,
            ])->filter(fn ($id) => $id && $id !== $actor->id)->unique();

            foreach ($userIds as $userId) {
                $owner = User::find($userId);
                if (! $owner) {
                    continue;
                }

                [$title, $body] = AppLocale::withUser($owner, fn () => $removed
                    ? [
                        '💸 '.__(':name ยกเลิกรายการจ่ายคืน', ['name' => $actorName]),
                        __(':from → :to ฿:amount ไม่นับแล้ว ยอดค้างกลับมาเหมือนเดิม', ['from' => $fromName, 'to' => $toName, 'amount' => $amount]),
                    ]
                    : [
                        '💸 '.__(':from จ่ายคืน :to ฿:amount แล้ว', ['from' => $fromName, 'to' => $toName, 'amount' => $amount]),
                        $settlement->method === TripSharedSettlement::METHOD_CASH
                            ? __(':name บันทึกว่าจ่ายเป็นเงินสด — ถ้าไม่ตรงกด "ยกเลิก" ที่รายการนั้นได้', ['name' => $actorName])
                            : __(':name บันทึกว่าโอนแล้ว — เช็กยอดเข้าบัญชี ถ้าไม่ตรงกด "ยกเลิก" ที่รายการนั้นได้', ['name' => $actorName]),
                    ]);

                SmartNotification::send((int) $userId, 'trip_expense_settled', $title, $body, [
                    'route' => 'shared_expenses',
                    'schedule_id' => (string) $schedule->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('SharedExpenseService: แจ้งเตือนการจ่ายคืนไม่สำเร็จ', ['settlement_id' => $settlement->id, 'error' => $e->getMessage()]);
        }
    }

    private function actorName(User $user): string
    {
        return $this->roster->shortName($user->nickname, $user->name);
    }

    // ── ตัวเลข ─────────────────────────────────────────────────────────────

    /** บาท → สตางค์ (จำนวนเต็ม) ปัดครึ่งขึ้น */
    public function satang(mixed $amount): int
    {
        if (! is_numeric($amount)) {
            return 0;
        }

        return (int) round(((float) $amount) * 100);
    }

    private function baht(int $satang): float
    {
        return round($satang / 100, 2);
    }

    /** "1,250" หรือ "1,250.50" */
    private function bahtLabel(int $satang): string
    {
        return $satang % 100 === 0
            ? number_format(intdiv($satang, 100))
            : number_format($satang / 100, 2);
    }

    private function nowThai(): Carbon
    {
        return Carbon::parse(now(self::TIMEZONE)->format('Y-m-d H:i:s'));
    }
}
