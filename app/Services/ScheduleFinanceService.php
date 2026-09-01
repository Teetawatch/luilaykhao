<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * บัญชีรอบเดินทางแบบเข้มงวด — เงินเข้า เงินออก กำไร และกติกาที่บังคับให้ตัวเลขเชื่อถือได้
 *
 * เดิมหน้าสรุปกำไรเป็นสมุดจดที่แก้ได้ตลอดกาลและไม่บังคับอะไรเลย รอบที่ออกทริป
 * ไปแล้วแต่ไม่มีใครคีย์ค่าใช้จ่ายจะโชว์กำไร 100% ซึ่งไม่จริง คลาสนี้จึงเพิ่มสาม
 * อย่างที่ทำให้ตัวเลขใช้ตัดสินใจได้:
 *   1. ปิดงบรอบ — ล็อกตัวเลข หลังจากนั้นแก้ได้เฉพาะแอดมินและต้องมีเหตุผล
 *   2. เงื่อนไขก่อนปิด — ไม่มีรายจ่าย/ยังมีลูกค้าค้างจ่าย/รายการไม่มีสลิป = ปิดไม่ได้
 *   3. ตัวเลขที่หายไป — ยอดจองเต็ม เงินค้างรับ ต้นทุนต่อหัว จุดคุ้มทุน และงบเทียบจริง
 *
 * ทุกข้อบังคับอ่านจาก [SiteSettings] — ปิด finance_strict_mode แล้วพฤติกรรมกลับไป
 * เหมือนเดิมทุกประการ
 */
class ScheduleFinanceService
{
    /** บุ๊คกิ้งที่ไม่นับเป็นรายได้ของรอบ */
    public const REVENUE_STATUSES_EXCLUDED = ['cancelled', 'expired'];

    public function __construct(
        private ScheduleLedgerService $ledgerService,
        private ExpenseAuditService $auditService,
    ) {}

    // ─── กติกาที่บังคับ ──────────────────────────────────────────

    /**
     * รอบนี้ยังแก้ตัวเลขได้ไหม — ปิดงบแล้วต้องเป็นแอดมินและต้องบอกเหตุผล
     *
     * @throws \Exception
     */
    public function assertEditable(TripSchedule $schedule, ?User $actor, ?string $reason = null): void
    {
        if (! $schedule->financeClosed()) {
            return;
        }

        if (! $actor?->hasRole('admin')) {
            throw new \Exception('รอบนี้ปิดงบแล้ว แก้ไขได้เฉพาะแอดมินเท่านั้น');
        }

        if (trim((string) $reason) === '') {
            throw new \Exception('รอบนี้ปิดงบแล้ว การแก้ไขต้องระบุเหตุผลกำกับทุกครั้ง');
        }
    }

    /**
     * หลักฐานและหมวดที่บังคับต่อหนึ่งรายการ
     *
     * @param  array  $data  ค่าที่กำลังจะบันทึก (kind/category/amount)
     * @param  bool  $hasSlip  มีสลิปแนบมาแล้วหรือของเดิมมีอยู่
     *
     * @throws \Exception
     */
    public function assertEvidence(array $data, bool $hasSlip): void
    {
        $kind = $data['kind'] ?? ScheduleExpense::KIND_EXPENSE;
        $amount = (float) ($data['amount'] ?? 0);

        if (SiteSettings::financeRequiresCategory() && empty($data['category'])) {
            throw new \Exception('ต้องระบุหมวดของรายการนี้ก่อนบันทึก');
        }

        $threshold = SiteSettings::financeSlipRequiredAbove();

        // บังคับเฉพาะฝั่งรายจ่าย — เงินที่รับเข้ามาไม่มีใบเสร็จให้ถ่าย
        if ($threshold !== null && $kind !== ScheduleExpense::KIND_INCOME && $amount > $threshold && ! $hasSlip) {
            throw new \Exception('รายจ่ายเกิน '.number_format($threshold).' บาท ต้องแนบสลิป/ใบเสร็จ');
        }
    }

    // ─── ปิดงบ / เปิดกลับ ────────────────────────────────────────

    /**
     * เช็กว่าปิดงบรอบนี้ได้หรือยัง — blockers ปิดไม่ได้, warnings ปิดได้แต่ควรรู้
     */
    public function closeChecks(TripSchedule $schedule): array
    {
        $summary = $this->summary($schedule);
        $strict = SiteSettings::financeStrict();
        $blockers = [];
        $warnings = [];

        if ($schedule->departure_date && $schedule->departure_date->isFuture()) {
            $warnings[] = ['code' => 'not_departed', 'message' => 'รอบนี้ยังไม่ถึงวันเดินทาง'];
        }

        if ($summary['expense_items_count'] === 0) {
            // รอบที่ไม่มีใครจองและไม่มีเงินเคลื่อนไหวเลย ไม่มีอะไรให้ทำบัญชี การบังคับ
            // ให้คีย์รายจ่ายก่อนปิดจึงกลายเป็นทางตัน: ปิดก็ไม่ได้ (ติดข้อนี้) เปิดรอบใหม่
            // ก็ไม่ได้ (ติด assertNoOverdueRounds) ทางออกเดียวคือปิดกติกาทั้งบริษัท
            // รอบแบบนี้ปิดได้เลย แต่ยังบอกไว้ให้รู้ว่าปิดเพราะไม่มีอะไรเกิดขึ้น
            if ($this->hadNoActivity($summary)) {
                $warnings[] = [
                    'code' => 'no_activity',
                    'message' => 'รอบนี้ไม่มีใบจองและไม่มีเงินเข้าออกเลย — ปิดงบได้โดยไม่ต้องมีรายจ่าย',
                ];
            } else {
                $entry = ['code' => 'no_expenses', 'message' => 'ยังไม่มีรายการค่าใช้จ่ายสักรายการ — กำไรที่เห็นจึงยังไม่ใช่ของจริง'];
                $strict && SiteSettings::bool('finance_close_requires_expense')
                    ? $blockers[] = $entry
                    : $warnings[] = $entry;
            }
        }

        if ($summary['unpaid_bookings_count'] > 0) {
            $entry = [
                'code' => 'outstanding',
                'message' => 'ยังมี '.$summary['unpaid_bookings_count'].' ใบจองค้างชำระรวม '.number_format($summary['outstanding'], 2).' บาท',
            ];
            $strict && SiteSettings::bool('finance_close_requires_settled')
                ? $blockers[] = $entry
                : $warnings[] = $entry;
        }

        if ($summary['missing_slip_count'] > 0) {
            $entry = [
                'code' => 'missing_slip',
                'message' => 'มี '.$summary['missing_slip_count'].' รายการที่ยอดเกินเกณฑ์แต่ไม่มีสลิป',
            ];
            $strict ? $blockers[] = $entry : $warnings[] = $entry;
        }

        if ($summary['uncategorised_count'] > 0) {
            $entry = [
                'code' => 'uncategorised',
                'message' => 'มี '.$summary['uncategorised_count'].' รายการที่ยังไม่ระบุหมวด',
            ];
            $strict && SiteSettings::financeRequiresCategory()
                ? $blockers[] = $entry
                : $warnings[] = $entry;
        }

        return [
            'can_close' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => $summary,
        ];
    }

    /**
     * รอบนี้ "ไม่เคยเกิดอะไรขึ้น" ใช่ไหม — ไม่มีใบจอง ไม่มียอดจอง ไม่มีเงินรับ คืน หรือรับหน้างาน
     *
     * ใช้ตัดสินว่ารอบที่ไม่มีรายจ่ายเป็นรอบที่ "ยังไม่ได้คีย์" (ต้องคีย์ก่อนปิด)
     * หรือรอบที่ "ไม่มีอะไรให้คีย์" (ปิดได้เลย) — รอบที่ไม่มีคนจองแล้วยังออกเดินทาง
     * จนมีค่าใช้จ่ายจริงจะไม่เข้าเงื่อนไขนี้ เพราะ expense_items_count จะไม่เป็นศูนย์
     */
    private function hadNoActivity(array $summary): bool
    {
        return $summary['bookings_count'] === 0
            && $summary['booked_total'] <= 0
            && $summary['paid_revenue'] <= 0
            && $summary['refunded'] <= 0
            && $summary['onsite_income'] <= 0;
    }

    /**
     * @throws \Exception
     */
    public function close(TripSchedule $schedule, User $actor, ?string $note = null): array
    {
        if ($schedule->financeClosed()) {
            throw new \Exception('รอบนี้ปิดงบไปแล้ว');
        }

        $checks = $this->closeChecks($schedule);

        if (! $checks['can_close']) {
            throw new \Exception('ปิดงบไม่ได้: '.collect($checks['blockers'])->pluck('message')->implode(' / '));
        }

        $schedule->forceFill([
            'finance_closed_at' => now(),
            'finance_closed_by' => $actor->id,
        ])->save();

        // เก็บยอด ณ วินาทีที่ปิดไว้ในปูม — ถ้าภายหลังมีการแก้ย้อนหลัง จะเทียบได้ว่าเพี้ยนไปจากตอนปิดเท่าไร
        $this->auditService->closed($schedule, $actor, $checks['summary'], $note);

        return $this->summary($schedule->fresh(['expenses', 'financeCloser']));
    }

    /**
     * @throws \Exception
     */
    public function reopen(TripSchedule $schedule, User $actor, string $reason): array
    {
        if (! $schedule->financeClosed()) {
            throw new \Exception('รอบนี้ยังไม่ได้ปิดงบ');
        }

        if (! $actor->hasRole('admin')) {
            throw new \Exception('เปิดงบกลับได้เฉพาะแอดมินเท่านั้น');
        }

        if (trim($reason) === '') {
            throw new \Exception('ต้องระบุเหตุผลที่เปิดงบกลับ');
        }

        $schedule->forceFill([
            'finance_closed_at' => null,
            'finance_closed_by' => null,
        ])->save();

        $this->auditService->reopened($schedule, $actor, $reason);

        return $this->summary($schedule->fresh(['expenses', 'financeCloser']));
    }

    // ─── รอบที่ค้างปิดงบ (ตัวบังคับให้ทุกรอบถูกปิด) ──────────────

    /**
     * รอบที่ทริปจบไปแล้วเกินกำหนดผ่อนผัน แต่ยังไม่มีใครปิดงบ
     *
     * นี่คือสิ่งที่ทำให้ "ทุกรอบต้องทำบัญชี" มีผลจริง — ไม่ปิดแล้วมันไม่ได้เงียบ
     * หายไป แต่ไปโผล่บนเมนู อีเมลรายวัน คิวงาน และบล็อกการเปิดรอบใหม่ของทริปนั้น
     *
     * รอบที่ถูกยกเลิกไม่นับ เพราะไม่มีเงินให้ปิด
     */
    public function overdueQuery(?int $tripId = null): Builder
    {
        $cutoff = now('Asia/Bangkok')->subDays(SiteSettings::financeCloseGraceDays())->toDateString();

        return TripSchedule::query()
            ->whereNull('finance_closed_at')
            ->where('status', '!=', 'cancelled')
            // รอบหลายวันนับจากวันกลับ ไม่ใช่วันออกเดินทาง
            ->whereRaw('COALESCE(return_date, departure_date) < ?', [$cutoff])
            ->when($tripId, fn ($q) => $q->where('trip_id', $tripId));
    }

    public function overdueCount(): int
    {
        return $this->overdueQuery()->count();
    }

    /**
     * รายการรอบค้างปิดงบ เรียงจากค้างนานที่สุด — พร้อมจำนวนวันที่เลยกำหนด
     */
    public function overdueRounds(int $limit = 100): array
    {
        // เทียบ "วัน" กับ "วัน" — คอลัมน์วันที่ถูก cast เป็น Carbon ที่เที่ยงคืน UTC
        // ส่วน now('Asia/Bangkok') อยู่ +07:00 เอามาลบกันตรง ๆ จะขาดไปหนึ่งวัน
        $today = Carbon::parse(now('Asia/Bangkok')->toDateString());

        return $this->overdueQuery()
            ->with('trip:id,title')
            ->orderByRaw('COALESCE(return_date, departure_date) ASC')
            ->limit($limit)
            ->get()
            ->map(function (TripSchedule $schedule) use ($today) {
                $ended = $schedule->return_date ?? $schedule->departure_date;

                return [
                    'schedule_id' => $schedule->id,
                    'trip_id' => $schedule->trip_id,
                    'trip_title' => $schedule->trip?->title ?? 'ไม่ทราบทริป',
                    'departure_label' => $schedule->departureLabelThai(),
                    'ended_on' => $ended?->toDateString(),
                    // เลยกำหนดมากี่วันแล้ว (นับจากวันที่ทริปจบ ไม่ใช่จากวันครบกำหนด)
                    'days_since_end' => $ended ? (int) $ended->startOfDay()->diffInDays($today) : null,
                    'expense_items_count' => $schedule->expenses()->count(),
                ];
            })
            ->all();
    }

    /**
     * เปิดรอบใหม่ของทริปนี้ได้ไหม — ทริปที่ยังมีรอบค้างปิดงบต้องเคลียร์ก่อน
     *
     * @throws \Exception
     */
    public function assertNoOverdueRounds(int $tripId): void
    {
        if (! SiteSettings::financeBlocksNewRounds()) {
            return;
        }

        $overdue = $this->overdueQuery($tripId)
            ->orderByRaw('COALESCE(return_date, departure_date) ASC')
            ->get();

        if ($overdue->isEmpty()) {
            return;
        }

        $labels = $overdue->take(3)->map(fn (TripSchedule $s) => $s->departureLabelThai())->implode(', ');
        $more = $overdue->count() > 3 ? ' และอีก '.($overdue->count() - 3).' รอบ' : '';

        throw new \Exception(
            'ทริปนี้ยังมีรอบที่เดินทางจบแล้วแต่ยังไม่ปิดงบ ('.$labels.$more.') '
            .'— ปิดงบรอบเดิมให้เรียบร้อยก่อนจึงจะเปิดรอบใหม่ได้'
        );
    }

    // ─── ตัวเลขของรอบ ────────────────────────────────────────────

    /**
     * ยอดสรุปเต็มของหนึ่งรอบ — ทั้งฝั่งเงินสดที่รับจริงและฝั่งที่ควรจะได้
     */
    public function summary(TripSchedule $schedule): array
    {
        $expenses = $schedule->relationLoaded('expenses')
            ? $schedule->expenses
            : $schedule->expenses()->get();

        return $this->composeSummary(
            $schedule,
            $this->revenueRow($schedule->id),
            $this->paxCount($schedule->id),
            $expenses,
        );
    }

    /**
     * @param  Collection<int, ScheduleExpense>  $expenses
     */
    public function composeSummary(TripSchedule $schedule, ?object $revenue, int $pax, Collection $expenses): array
    {
        $paid = round((float) ($revenue->paid ?? 0), 2);
        $refunded = round((float) ($revenue->refunded ?? 0), 2);
        $booked = round((float) ($revenue->booked ?? 0), 2);
        $paidRevenue = round($paid - $refunded, 2);

        $ledger = $this->ledgerService->totals($expenses);
        $expenseTotal = $ledger['expense_total'];
        $onsite = $ledger['income_total'];

        $profit = round($paidRevenue + $onsite - $expenseTotal, 2);
        $totalRevenue = round($paidRevenue + $onsite, 2);

        // เงินที่ยังไม่เข้า — ยอดจองเต็มลบที่จ่ายมาแล้ว (ไม่ให้ติดลบจากการจ่ายเกิน)
        $outstanding = round(max($booked - $paid, 0), 2);
        $potentialProfit = round($profit + $outstanding, 2);

        $budget = $this->budgetFor($schedule);
        $revenuePerPax = $pax > 0 ? round($totalRevenue / $pax, 2) : round((float) $schedule->effective_price, 2);

        return [
            'schedule_id' => $schedule->id,
            'bookings_count' => (int) ($revenue->bookings_count ?? 0),
            'passengers_count' => $pax,
            // ── เงินสดที่รับจริง (ฐานของกำไรที่โชว์) ──
            'paid_revenue' => $paidRevenue,
            'refunded' => $refunded,
            'onsite_income' => $onsite,
            'expense_total' => $expenseTotal,
            'profit' => $profit,
            'margin_percent' => $totalRevenue > 0 ? round($profit / $totalRevenue * 100, 1) : null,
            // ── ฝั่งที่ควรจะได้ ──
            'booked_total' => $booked,
            'outstanding' => $outstanding,
            'unpaid_bookings_count' => (int) ($revenue->unpaid_count ?? 0),
            'potential_profit' => $potentialProfit,
            // ── ต้นทุนและจุดคุ้มทุน ──
            'cost_per_pax' => $pax > 0 ? round($expenseTotal / $pax, 2) : null,
            'revenue_per_pax' => $revenuePerPax,
            'break_even_pax' => $revenuePerPax > 0 ? (int) ceil($expenseTotal / $revenuePerPax) : null,
            // ── งบเทียบจริง ──
            'budget' => $budget,
            'budget_variance' => $budget !== null ? round($budget - $expenseTotal, 2) : null,
            'budget_used_percent' => $budget > 0 ? round($expenseTotal / $budget * 100, 1) : null,
            'over_budget' => $budget !== null && $expenseTotal > $budget,
            // ── คุณภาพของข้อมูล ──
            'expense_items_count' => $expenses->count(),
            'missing_slip_count' => $this->missingSlipCount($expenses),
            'uncategorised_count' => $expenses->whereNull('category')->count(),
            // ── สถานะปิดงบ ──
            'is_closed' => $schedule->financeClosed(),
            'closed_at' => $schedule->finance_closed_at?->toIso8601String(),
            'closed_by_name' => $schedule->relationLoaded('financeCloser') ? $schedule->financeCloser?->name : null,
        ];
    }

    /**
     * งบของรอบ — ตั้งเองต่อรอบ ไม่งั้นใช้ผลรวมรายการประจำที่เปิดใช้อยู่ของทริป
     */
    public function budgetFor(TripSchedule $schedule): ?float
    {
        if ($schedule->finance_budget !== null) {
            return round((float) $schedule->finance_budget, 2);
        }

        $fromTemplates = ExpenseTemplate::where('trip_id', $schedule->trip_id)
            ->where('is_active', true)
            ->sum('default_amount');

        return $fromTemplates > 0 ? round((float) $fromTemplates, 2) : null;
    }

    /**
     * รายจ่ายที่เกินเพดานแต่ไม่มีสลิป — ตัวเลขนี้คือสิ่งที่กันไม่ให้ปิดงบ
     *
     * @param  Collection<int, ScheduleExpense>  $expenses
     */
    public function missingSlipCount(Collection $expenses): int
    {
        $threshold = SiteSettings::financeSlipRequiredAbove();

        if ($threshold === null) {
            return 0;
        }

        return $expenses
            ->where('kind', '!=', ScheduleExpense::KIND_INCOME)
            ->filter(fn (ScheduleExpense $e) => (float) $e->amount > $threshold && ! $e->slip_path)
            ->count();
    }

    // ─── ค่าตอบแทนทีมงาน ────────────────────────────────────────

    /**
     * ลงรายการค่าจ้างทีมงานจากเรตต่อวันที่ตั้งไว้บนโปรไฟล์ — ข้ามคนที่ลงไปแล้ว
     *
     * @return array{created:int, skipped:int, total:float}
     */
    public function applyStaffCost(TripSchedule $schedule, User $actor): array
    {
        $days = $this->tripDays($schedule);
        $existing = $schedule->expenses()
            ->where('category', 'staff')
            ->pluck('name')
            ->all();

        $created = 0;
        $skipped = 0;
        $total = 0.0;

        foreach ($schedule->activeStaff as $staff) {
            $rate = (float) ($staff->staff_day_rate ?? 0);
            $name = 'ค่าตอบแทน '.$staff->name;

            if ($rate <= 0) {
                $skipped++;

                continue;
            }

            if (in_array($name, $existing, true)) {
                $skipped++;

                continue;
            }

            $amount = round($rate * $days, 2);
            $expense = $schedule->expenses()->create([
                'kind' => ScheduleExpense::KIND_EXPENSE,
                'category' => 'staff',
                'name' => $name,
                'amount' => $amount,
                'note' => number_format($rate, 2).' บาท × '.$days.' วัน',
                'spent_at' => $schedule->departure_date?->toDateString(),
                'created_by' => $actor->id,
            ]);

            $this->auditService->created($expense, $actor, 'ลงค่าตอบแทนทีมงานอัตโนมัติ');
            $created++;
            $total += $amount;
        }

        return ['created' => $created, 'skipped' => $skipped, 'total' => round($total, 2)];
    }

    /** จำนวนวันของรอบ — ใช้คูณเรตต่อวันของทีมงาน อย่างน้อย 1 วันเสมอ */
    public function tripDays(TripSchedule $schedule): int
    {
        if ($schedule->departure_date && $schedule->return_date) {
            return max(1, $schedule->departure_date->diffInDays($schedule->return_date) + 1);
        }

        return max(1, (int) ($schedule->trip?->duration_days ?? 1));
    }

    // ─── Query helpers ──────────────────────────────────────────

    /**
     * ยอดเงินของรอบเดียว — คืน object เดียวกับที่ [revenueRows] คืนต่อรอบ
     */
    public function revenueRow(int $scheduleId): ?object
    {
        return $this->revenueRows([$scheduleId])->get($scheduleId);
    }

    /**
     * ยอดเงินรายรอบ — จอง/จ่ายแล้ว/คืนแล้ว/จำนวนใบที่ยังค้าง
     *
     * @param  iterable<int>  $scheduleIds
     * @return Collection<int, object>
     */
    public function revenueRows(iterable $scheduleIds): Collection
    {
        return Booking::whereIn('schedule_id', $scheduleIds)
            ->whereNotIn('status', self::REVENUE_STATUSES_EXCLUDED)
            ->selectRaw(
                'schedule_id,
                 SUM(paid_amount) as paid,
                 SUM(refund_amount) as refunded,
                 SUM(total_amount) as booked,
                 COUNT(*) as bookings_count,
                 SUM(CASE WHEN total_amount > paid_amount THEN 1 ELSE 0 END) as unpaid_count'
            )
            ->groupBy('schedule_id')
            ->get()
            ->keyBy('schedule_id');
    }

    public function paxCount(int $scheduleId): int
    {
        return (int) ($this->paxCounts([$scheduleId])[$scheduleId] ?? 0);
    }

    /**
     * @param  iterable<int>  $scheduleIds
     * @return Collection<int, int>
     */
    public function paxCounts(iterable $scheduleIds): Collection
    {
        return BookingPassenger::query()
            ->join('bookings', 'booking_passengers.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.schedule_id', $scheduleIds)
            ->whereNotIn('bookings.status', self::REVENUE_STATUSES_EXCLUDED)
            ->selectRaw('bookings.schedule_id as schedule_id, COUNT(booking_passengers.id) as pax')
            ->groupBy('bookings.schedule_id')
            ->pluck('pax', 'schedule_id');
    }
}
