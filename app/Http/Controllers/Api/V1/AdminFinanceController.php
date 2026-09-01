<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\ExpenseAuditService;
use App\Services\ScheduleFinanceService;
use App\Services\ScheduleLedgerService;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * บัญชีทริปฝั่งแอดมิน — ค่าใช้จ่าย รายรับ กำไร และการปิดงบรอบ
 *
 * กติกาทั้งหมด (ล็อกหลังปิดงบ, บังคับสลิป/หมวด, ปูมการแก้ไข) อยู่ที่
 * [ScheduleFinanceService] ที่เดียว เพื่อให้ฝั่งสตาฟที่จดหน้างานผ่านแอป
 * ([StaffController]) โดนกติกาชุดเดียวกันโดยไม่ต้องคัดลอกเงื่อนไข
 */
class AdminFinanceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ScheduleLedgerService $ledgerService,
        private ScheduleFinanceService $financeService,
        private ExpenseAuditService $auditService,
    ) {}

    /**
     * Bookings ที่นับเป็นรายรับ — ตัด cancelled/expired ออก
     * "เงินที่รับจริง" = paid_amount − refund_amount
     */
    private const REVENUE_STATUSES_EXCLUDED = ScheduleFinanceService::REVENUE_STATUSES_EXCLUDED;

    // ─── สรุปกำไรระดับทริป ──────────────────────────────────────

    public function tripProfitSummary(Request $request): JsonResponse
    {
        $from = $request->get('from');
        $to = $request->get('to');

        $applyDate = function ($query) use ($from, $to) {
            return $query
                ->when($from, fn ($q) => $q->whereDate('trip_schedules.departure_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('trip_schedules.departure_date', '<=', $to));
        };

        // รายรับต่อทริป — ทั้งเงินที่เข้าแล้วและยอดจองเต็ม (ส่วนต่าง = เงินค้างรับ)
        $revenue = $applyDate(
            Booking::query()
                ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
                ->whereNotIn('bookings.status', self::REVENUE_STATUSES_EXCLUDED)
        )
            ->selectRaw(
                'trip_schedules.trip_id as trip_id,
                 SUM(bookings.paid_amount) as paid,
                 SUM(bookings.refund_amount) as refunded,
                 SUM(bookings.total_amount) as booked,
                 COUNT(bookings.id) as bookings_count,
                 SUM(CASE WHEN bookings.total_amount > bookings.paid_amount THEN 1 ELSE 0 END) as unpaid_count'
            )
            ->groupBy('trip_schedules.trip_id')
            ->get()
            ->keyBy('trip_id');

        // จำนวนผู้เดินทางต่อทริป
        $pax = $applyDate(
            BookingPassenger::query()
                ->join('bookings', 'booking_passengers.booking_id', '=', 'bookings.id')
                ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
                ->whereNotIn('bookings.status', self::REVENUE_STATUSES_EXCLUDED)
        )
            ->selectRaw('trip_schedules.trip_id as trip_id, COUNT(booking_passengers.id) as pax')
            ->groupBy('trip_schedules.trip_id')
            ->pluck('pax', 'trip_id');

        // ค่าใช้จ่าย/รายรับหน้างานต่อทริป — แถวที่สตาฟจดว่าเป็นรายรับ (kind=income)
        // ต้องไม่ถูกนับเป็นค่าใช้จ่าย ไม่งั้นกำไรจะเพี้ยนสองเท่าของยอดนั้น
        $expenses = $applyDate(
            ScheduleExpense::query()
                ->join('trip_schedules', 'schedule_expenses.schedule_id', '=', 'trip_schedules.id')
        )
            ->selectRaw(
                'trip_schedules.trip_id as trip_id,
                 SUM(CASE WHEN schedule_expenses.kind = ? THEN 0 ELSE schedule_expenses.amount END) as expenses,
                 SUM(CASE WHEN schedule_expenses.kind = ? THEN schedule_expenses.amount ELSE 0 END) as onsite_income,
                 COUNT(schedule_expenses.id) as items_count',
                [ScheduleExpense::KIND_INCOME, ScheduleExpense::KIND_INCOME]
            )
            ->groupBy('trip_schedules.trip_id')
            ->get()
            ->keyBy('trip_id');

        // รอบที่ยังไม่ปิดงบต่อทริป — ตัวเลขของทริปนี้จะยังขยับได้อีกแค่ไหน
        $openRounds = $applyDate(TripSchedule::query()->from('trip_schedules'))
            ->whereNull('trip_schedules.finance_closed_at')
            ->selectRaw('trip_schedules.trip_id as trip_id, COUNT(*) as open_count')
            ->groupBy('trip_schedules.trip_id')
            ->pluck('open_count', 'trip_id');

        $tripIds = $revenue->keys()->merge($expenses->keys())->unique();
        $trips = Trip::whereIn('id', $tripIds)->get(['id', 'title', 'type'])->keyBy('id');

        $rows = $tripIds->map(function ($tripId) use ($trips, $revenue, $expenses, $pax, $openRounds) {
            $rev = $revenue->get($tripId);
            $exp = $expenses->get($tripId);

            $paid = round((float) ($rev->paid ?? 0), 2);
            $booked = round((float) ($rev->booked ?? 0), 2);
            $paidRevenue = round($paid - (float) ($rev->refunded ?? 0), 2);
            $expenseTotal = round((float) ($exp->expenses ?? 0), 2);
            $onsiteIncome = round((float) ($exp->onsite_income ?? 0), 2);
            $profit = round($paidRevenue + $onsiteIncome - $expenseTotal, 2);
            $totalRevenue = round($paidRevenue + $onsiteIncome, 2);
            $outstanding = round(max($booked - $paid, 0), 2);
            $paxCount = (int) ($pax->get($tripId) ?? 0);

            return [
                'trip_id' => (int) $tripId,
                'title' => $trips->get($tripId)?->title ?? 'ไม่ทราบ',
                'type' => $trips->get($tripId)?->type,
                'bookings_count' => (int) ($rev->bookings_count ?? 0),
                'passengers_count' => $paxCount,
                'paid_revenue' => $paidRevenue,
                'onsite_income' => $onsiteIncome,
                'expense_total' => $expenseTotal,
                'expense_items_count' => (int) ($exp->items_count ?? 0),
                'profit' => $profit,
                'margin_percent' => $totalRevenue > 0 ? round($profit / $totalRevenue * 100, 1) : null,
                // ฝั่งที่ควรจะได้ — กำไรจะขยับอีกเท่าไรถ้าเก็บเงินครบทุกใบ
                'booked_total' => $booked,
                'outstanding' => $outstanding,
                'unpaid_bookings_count' => (int) ($rev->unpaid_count ?? 0),
                'potential_profit' => round($profit + $outstanding, 2),
                'cost_per_pax' => $paxCount > 0 ? round($expenseTotal / $paxCount, 2) : null,
                'open_rounds' => (int) ($openRounds->get($tripId) ?? 0),
            ];
        })->sortByDesc('profit')->values();

        $summary = [
            'period' => $from || $to ? trim(($from ?? '…').' ถึง '.($to ?? '…')) : 'ทั้งหมด',
            'trips_count' => $rows->count(),
            'paid_revenue' => round($rows->sum('paid_revenue'), 2),
            'onsite_income' => round($rows->sum('onsite_income'), 2),
            'expense_total' => round($rows->sum('expense_total'), 2),
            'profit' => round($rows->sum('profit'), 2),
            'booked_total' => round($rows->sum('booked_total'), 2),
            'outstanding' => round($rows->sum('outstanding'), 2),
            'potential_profit' => round($rows->sum('potential_profit'), 2),
            'open_rounds' => (int) $rows->sum('open_rounds'),
        ];

        return $this->success([
            'summary' => $summary,
            'trips' => $rows,
            'rules' => $this->rulesPayload(),
        ]);
    }

    /**
     * แดชบอร์ดรายเดือน — รายรับ/ค่าใช้จ่าย/กำไรของทุกรอบในช่วงที่เลือก
     *
     * รวมเป็นเดือนในฝั่ง PHP ไม่ใช่ SQL เพราะฟังก์ชันตัดเดือนต่างกันไปตาม
     * ฐานข้อมูล (MySQL/Postgres/SQLite) และรอบเดินทางไม่ได้มีเป็นแสนแถว
     */
    public function dashboard(Request $request): JsonResponse
    {
        $from = $request->get('from') ?: now('Asia/Bangkok')->subMonths(11)->startOfMonth()->toDateString();
        $to = $request->get('to') ?: now('Asia/Bangkok')->endOfMonth()->toDateString();

        $schedules = TripSchedule::with('trip:id,title')
            ->whereDate('departure_date', '>=', $from)
            ->whereDate('departure_date', '<=', $to)
            ->get();

        if ($schedules->isEmpty()) {
            return $this->success(['period' => ['from' => $from, 'to' => $to], 'months' => [], 'totals' => $this->emptyTotals()]);
        }

        $ids = $schedules->pluck('id');
        $revenue = $this->financeService->revenueRows($ids);
        $pax = $this->financeService->paxCounts($ids);
        $expenses = ScheduleExpense::whereIn('schedule_id', $ids)->get()->groupBy('schedule_id');

        $buckets = [];

        foreach ($schedules as $schedule) {
            $summary = $this->financeService->composeSummary(
                $schedule,
                $revenue->get($schedule->id),
                (int) ($pax->get($schedule->id) ?? 0),
                collect($expenses->get($schedule->id) ?? []),
            );

            $key = $schedule->departure_date?->format('Y-m') ?? 'unknown';
            $bucket = $buckets[$key] ?? [
                'month' => $key,
                'label' => $schedule->departure_date ? ThaiDate::monthYear($schedule->departure_date) : 'ไม่ทราบเดือน',
                'rounds' => 0, 'closed_rounds' => 0, 'passengers' => 0,
                'paid_revenue' => 0.0, 'onsite_income' => 0.0, 'expense_total' => 0.0,
                'profit' => 0.0, 'outstanding' => 0.0,
            ];

            $bucket['rounds']++;
            $bucket['closed_rounds'] += $summary['is_closed'] ? 1 : 0;
            $bucket['passengers'] += $summary['passengers_count'];
            $bucket['paid_revenue'] += $summary['paid_revenue'];
            $bucket['onsite_income'] += $summary['onsite_income'];
            $bucket['expense_total'] += $summary['expense_total'];
            $bucket['profit'] += $summary['profit'];
            $bucket['outstanding'] += $summary['outstanding'];
            $buckets[$key] = $bucket;
        }

        ksort($buckets);

        $months = collect($buckets)->map(function (array $b) {
            $revenue = $b['paid_revenue'] + $b['onsite_income'];

            return [
                ...$b,
                'paid_revenue' => round($b['paid_revenue'], 2),
                'onsite_income' => round($b['onsite_income'], 2),
                'expense_total' => round($b['expense_total'], 2),
                'profit' => round($b['profit'], 2),
                'outstanding' => round($b['outstanding'], 2),
                'margin_percent' => $revenue > 0 ? round($b['profit'] / $revenue * 100, 1) : null,
            ];
        })->values();

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'months' => $months,
            'totals' => [
                'rounds' => (int) $months->sum('rounds'),
                'closed_rounds' => (int) $months->sum('closed_rounds'),
                'passengers' => (int) $months->sum('passengers'),
                'paid_revenue' => round($months->sum('paid_revenue'), 2),
                'onsite_income' => round($months->sum('onsite_income'), 2),
                'expense_total' => round($months->sum('expense_total'), 2),
                'profit' => round($months->sum('profit'), 2),
                'outstanding' => round($months->sum('outstanding'), 2),
            ],
        ]);
    }

    /**
     * รอบที่ค้างปิดงบ — งานที่ต้องเคลียร์ ไม่ใช่ตัวเลขให้ดูเฉย ๆ
     *
     * แยก endpoint จากหน้าสรุปเพราะเมนูฝั่งซ้ายเรียกแค่จำนวนมาแปะ badge
     * ทุกครั้งที่เปิดหลังบ้าน ไม่ควรลากยอดเงินทั้งระบบมาด้วย
     */
    public function overdue(): JsonResponse
    {
        return $this->success([
            'grace_days' => SiteSettings::financeCloseGraceDays(),
            'blocks_new_rounds' => SiteSettings::financeBlocksNewRounds(),
            'count' => $this->financeService->overdueCount(),
            'rounds' => $this->financeService->overdueRounds(),
        ]);
    }

    public function overdueCount(): JsonResponse
    {
        return $this->success(['count' => $this->financeService->overdueCount()]);
    }

    // ─── สรุปกำไรรายรอบเดินทางของทริปหนึ่ง ───────────────────────

    public function tripScheduleProfit(int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);

        $schedules = $trip->schedules()
            ->with(['expenses.creator:id,name', 'financeCloser:id,name'])
            ->orderByDesc('departure_date')
            ->get();

        $scheduleIds = $schedules->pluck('id');
        $revenue = $this->financeService->revenueRows($scheduleIds);
        $pax = $this->financeService->paxCounts($scheduleIds);

        $rows = $schedules->map(function (TripSchedule $schedule) use ($revenue, $pax) {
            $summary = $this->financeService->composeSummary(
                $schedule,
                $revenue->get($schedule->id),
                (int) ($pax->get($schedule->id) ?? 0),
                $schedule->expenses,
            );

            return [
                ...$summary,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_label' => $schedule->departureLabelThai(),
                'status' => $schedule->status,
                'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            ];
        })->values();

        return $this->success([
            'trip' => ['id' => $trip->id, 'title' => $trip->title, 'type' => $trip->type],
            'totals' => [
                'paid_revenue' => round($rows->sum('paid_revenue'), 2),
                'onsite_income' => round($rows->sum('onsite_income'), 2),
                'expense_total' => round($rows->sum('expense_total'), 2),
                'profit' => round($rows->sum('profit'), 2),
                'outstanding' => round($rows->sum('outstanding'), 2),
                'potential_profit' => round($rows->sum('potential_profit'), 2),
            ],
            'schedules' => $rows,
            'rules' => $this->rulesPayload(),
        ]);
    }

    // ─── รายการประจำต่อทริป (CRUD) ──────────────────────────────

    public function templates(int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);

        return $this->success($trip->expenseTemplates()->get());
    }

    public function storeTemplate(Request $request, int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_keys(ScheduleExpense::EXPENSE_CATEGORIES))],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template = $trip->expenseTemplates()->create($validated);

        return $this->success($template, 'เพิ่มรายการประจำสำเร็จ', 201);
    }

    public function updateTemplate(Request $request, int $tripId, int $id): JsonResponse
    {
        $template = ExpenseTemplate::where('trip_id', $tripId)->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', Rule::in(array_keys(ScheduleExpense::EXPENSE_CATEGORIES))],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $template->update($validated);

        return $this->success($template, 'แก้ไขรายการประจำสำเร็จ');
    }

    public function deleteTemplate(int $tripId, int $id): JsonResponse
    {
        $template = ExpenseTemplate::where('trip_id', $tripId)->findOrFail($id);
        $template->delete();

        return $this->success(null, 'ลบรายการประจำสำเร็จ');
    }

    // ─── ค่าใช้จ่ายต่อรอบเดินทาง (CRUD + ใช้รายการประจำ) ──────────

    public function expenses(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['expenses.creator:id,name', 'financeCloser:id,name'])->findOrFail($scheduleId);

        return $this->success([
            'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            'summary' => $this->financeService->summary($schedule),
            'categories' => $this->categoriesPayload(),
            'rules' => $this->rulesPayload(),
        ]);
    }

    public function storeExpense(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'expense_template_id' => ['nullable', 'integer'],
            'name' => ['required_without:expense_template_id', 'nullable', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            // แอดมินก็คีย์รายรับหน้างานได้ (เช่นเก็บเงินสดแล้วสตาฟลืมจด)
            'kind' => ['sometimes', Rule::in(ScheduleExpense::KINDS)],
            'category' => ['nullable', 'string', 'max:32'],
            'spent_at' => ['nullable', 'date'],
            'slip' => ['nullable', 'image', 'max:5120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $name = $validated['name'] ?? null;
        $amount = $validated['amount'] ?? null;
        $templateId = null;
        $templateCategory = null;

        if (! empty($validated['expense_template_id'])) {
            // รายการประจำต้องเป็นของทริปเดียวกับรอบนี้
            $template = ExpenseTemplate::where('trip_id', $schedule->trip_id)
                ->findOrFail($validated['expense_template_id']);
            $templateId = $template->id;
            $name = $name ?: $template->name;
            $amount = $amount ?? $template->default_amount;
            $templateCategory = $template->category;
        }

        $payload = [
            'kind' => $validated['kind'] ?? ScheduleExpense::KIND_EXPENSE,
            'category' => $validated['category'] ?? ($templateCategory ?? null),
            'name' => $name,
            'amount' => $amount ?? 0,
            'note' => $validated['note'] ?? null,
            'spent_at' => $validated['spent_at'] ?? null,
        ];

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $validated['reason'] ?? null);
            $this->financeService->assertEvidence($payload, $request->hasFile('slip'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $expense = $this->ledgerService->record(
            $schedule,
            $request->user(),
            $payload,
            $request->file('slip'),
            $validated['reason'] ?? null,
        );

        // record() ตั้ง spent_at เป็นวันนี้เมื่อไม่ได้ระบุ ส่วน template ต้องคงการอ้างที่มาไว้
        if ($templateId) {
            $expense->forceFill(['expense_template_id' => $templateId])->save();
        }

        return $this->success([
            'expense' => $this->expensePayload($expense->load('creator:id,name')),
            'summary' => $this->financeService->summary($schedule->fresh('expenses')),
        ], 'เพิ่มค่าใช้จ่ายสำเร็จ', 201);
    }

    public function applyTemplates(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $request->get('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $templates = ExpenseTemplate::where('trip_id', $schedule->trip_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        // ข้ามรายการประจำที่ถูกเพิ่มเข้ารอบนี้ไปแล้ว กันการซ้ำเมื่อกดหลายครั้ง
        $alreadyApplied = $schedule->expenses()
            ->whereNotNull('expense_template_id')
            ->pluck('expense_template_id')
            ->all();

        $created = 0;
        foreach ($templates as $template) {
            if (in_array($template->id, $alreadyApplied, true)) {
                continue;
            }

            $expense = $schedule->expenses()->create([
                'expense_template_id' => $template->id,
                'category' => $template->category,
                'name' => $template->name,
                'amount' => $template->default_amount ?? 0,
                'created_by' => $request->user()?->id,
            ]);
            $this->auditService->created($expense, $request->user(), 'ดึงจากรายการประจำ');
            $created++;
        }

        $schedule->load('expenses.creator:id,name');

        return $this->success([
            'created' => $created,
            'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            'summary' => $this->financeService->summary($schedule),
        ], $created > 0 ? "เพิ่มรายการประจำ {$created} รายการ" : 'ไม่มีรายการประจำใหม่ให้เพิ่ม');
    }

    /**
     * คัดลอกค่าใช้จ่ายที่เลือกจากรอบนี้ไปยังรอบอื่น — จะได้ไม่ต้องพิมพ์ใหม่ทุกรอบ
     * คัดลอกเป็นรายการอิสระ (ไม่ผูก template) เพื่อไม่ให้อ้างข้าม template ของทริปอื่น
     */
    public function copyExpensesTo(Request $request, int $scheduleId): JsonResponse
    {
        $source = TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'expense_ids' => ['required', 'array', 'min:1'],
            'expense_ids.*' => ['integer'],
            'target_schedule_ids' => ['required', 'array', 'min:1'],
            'target_schedule_ids.*' => ['integer'],
        ]);

        $sourceExpenses = ScheduleExpense::where('schedule_id', $source->id)
            ->whereIn('id', $validated['expense_ids'])
            ->get();

        if ($sourceExpenses->isEmpty()) {
            return $this->error('ไม่พบรายการค่าใช้จ่ายที่เลือก', 422);
        }

        // ตัดรอบต้นทางออก เผื่อถูกเลือกมาด้วย
        $targets = TripSchedule::whereIn('id', $validated['target_schedule_ids'])
            ->where('id', '!=', $source->id)
            ->get();

        if ($targets->isEmpty()) {
            return $this->error('กรุณาเลือกรอบเดินทางปลายทางอย่างน้อย 1 รอบ', 422);
        }

        // รอบปลายทางที่ปิดงบไปแล้วห้ามรับของใหม่เงียบ ๆ — บอกให้ชัดว่ารอบไหน
        $closed = $targets->filter(fn (TripSchedule $s) => $s->financeClosed());

        if ($closed->isNotEmpty()) {
            return $this->error('รอบปลายทางที่ปิดงบแล้วรับรายการใหม่ไม่ได้: '.$closed->map(fn ($s) => $s->departureLabelThai())->implode(', '), 422);
        }

        $created = 0;
        foreach ($targets as $target) {
            foreach ($sourceExpenses as $expense) {
                $copy = $target->expenses()->create([
                    'expense_template_id' => null,
                    // คัดลอกฝั่งบัญชีและหมวดไปด้วย ไม่งั้นรายรับจะกลายเป็นรายจ่าย
                    // ที่ปลายทาง (สลิปไม่คัดลอก — เป็นหลักฐานของครั้งนั้นครั้งเดียว)
                    'kind' => $expense->kind ?: ScheduleExpense::KIND_EXPENSE,
                    'category' => $expense->category,
                    'name' => $expense->name,
                    'amount' => $expense->amount,
                    'note' => $expense->note,
                    'created_by' => $request->user()?->id,
                ]);
                $this->auditService->created($copy, $request->user(), 'คัดลอกจากรอบ '.$source->departureLabelThai());
                $created++;
            }
        }

        return $this->success([
            'created' => $created,
            'targets_count' => $targets->count(),
        ], "คัดลอก {$sourceExpenses->count()} รายการไปยัง {$targets->count()} รอบเดินทาง");
    }

    public function updateExpense(Request $request, int $scheduleId, int $id): JsonResponse
    {
        $expense = ScheduleExpense::where('schedule_id', $scheduleId)->findOrFail($id);
        $schedule = TripSchedule::findOrFail($scheduleId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
            'kind' => ['sometimes', Rule::in(ScheduleExpense::KINDS)],
            'category' => ['nullable', 'string', 'max:32'],
            'spent_at' => ['nullable', 'date'],
            'slip' => ['nullable', 'image', 'max:5120'],
            'remove_slip' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        // หลักฐานหลังแก้ — ของเดิมยังอยู่ไหม หรือกำลังถ่ายใหม่/สั่งเอาออก
        $hasSlip = $request->hasFile('slip')
            || ($expense->slip_path && ! ($validated['remove_slip'] ?? false));

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $validated['reason'] ?? null);
            $this->financeService->assertEvidence([
                'kind' => $validated['kind'] ?? $expense->kind,
                'category' => array_key_exists('category', $validated) ? $validated['category'] : $expense->category,
                'amount' => $validated['amount'] ?? $expense->amount,
            ], (bool) $hasSlip);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $updated = $this->ledgerService->update(
            $expense,
            $validated,
            $request->file('slip'),
            $request->user(),
            $validated['reason'] ?? null,
        );

        return $this->success([
            'expense' => $this->expensePayload($updated),
            'summary' => $this->financeService->summary($schedule->fresh('expenses')),
        ], 'แก้ไขค่าใช้จ่ายสำเร็จ');
    }

    public function deleteExpense(Request $request, int $scheduleId, int $id): JsonResponse
    {
        $expense = ScheduleExpense::where('schedule_id', $scheduleId)->findOrFail($id);
        $schedule = TripSchedule::findOrFail($scheduleId);

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $request->get('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        // ผ่าน service เพื่อให้ปูมบันทึกว่าใครลบยอดนี้ออกจากงบ (สลิปยังอยู่เป็นหลักฐาน)
        $this->ledgerService->delete($expense, $request->user(), $request->get('reason'));

        return $this->success([
            'summary' => $this->financeService->summary($schedule->fresh('expenses')),
        ], 'ลบค่าใช้จ่ายสำเร็จ');
    }

    // ─── ปิดงบ / ปูม / งบประมาณ / ค่าทีมงาน ─────────────────────

    public function closeCheck(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['expenses', 'financeCloser:id,name'])->findOrFail($scheduleId);

        return $this->success($this->financeService->closeChecks($schedule));
    }

    public function close(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['expenses', 'financeCloser:id,name'])->findOrFail($scheduleId);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $summary = $this->financeService->close($schedule, $request->user(), $validated['note'] ?? null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['summary' => $summary], 'ปิดงบรอบเดินทางแล้ว');
    }

    public function reopen(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['expenses', 'financeCloser:id,name'])->findOrFail($scheduleId);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $summary = $this->financeService->reopen($schedule, $request->user(), $validated['reason']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['summary' => $summary], 'เปิดงบรอบเดินทางกลับแล้ว');
    }

    public function audits(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        return $this->success(['audits' => $this->auditService->forSchedule($schedule)]);
    }

    public function updateBudget(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('expenses')->findOrFail($scheduleId);

        $validated = $request->validate([
            // null = กลับไปใช้ผลรวมของรายการประจำ
            'finance_budget' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $request->get('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $schedule->update(['finance_budget' => $validated['finance_budget'] ?? null]);

        return $this->success([
            'summary' => $this->financeService->summary($schedule->fresh('expenses')),
        ], 'บันทึกงบของรอบแล้ว');
    }

    public function applyStaffCost(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with(['expenses', 'activeStaff', 'trip'])->findOrFail($scheduleId);

        try {
            $this->financeService->assertEditable($schedule, $request->user(), $request->get('reason'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $result = $this->financeService->applyStaffCost($schedule, $request->user());

        $message = $result['created'] > 0
            ? "ลงค่าตอบแทนทีมงาน {$result['created']} คน รวม ".number_format($result['total'], 2).' บาท'
            : 'ไม่มีทีมงานที่ยังไม่ได้ลงค่าตอบแทน (หรือยังไม่ได้ตั้งเรตต่อวัน)';

        $schedule->load('expenses.creator:id,name');

        return $this->success([
            ...$result,
            'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            'summary' => $this->financeService->summary($schedule),
        ], $message);
    }

    /**
     * งบของรอบเป็น CSV — เปิดใน Excel ได้ตรง ๆ (มี BOM นำหน้าไม่งั้นภาษาไทยเพี้ยน)
     */
    public function exportSchedule(int $scheduleId): Response
    {
        $schedule = TripSchedule::with(['expenses.creator:id,name', 'trip', 'financeCloser:id,name'])->findOrFail($scheduleId);
        $summary = $this->financeService->summary($schedule);

        $lines = [
            ['ทริป', $schedule->trip?->title],
            ['รอบเดินทาง', $schedule->departureLabelThai()],
            ['สถานะงบ', $summary['is_closed'] ? 'ปิดงบแล้ว' : 'ยังไม่ปิดงบ'],
            [],
            ['ยอดจองเต็ม', $summary['booked_total']],
            ['รับเงินแล้ว (สุทธิคืนเงิน)', $summary['paid_revenue']],
            ['ค้างรับ', $summary['outstanding']],
            ['รับหน้างาน', $summary['onsite_income']],
            ['ค่าใช้จ่ายรวม', $summary['expense_total']],
            ['กำไร', $summary['profit']],
            ['กำไรถ้าเก็บครบ', $summary['potential_profit']],
            ['ผู้เดินทาง', $summary['passengers_count']],
            ['ต้นทุนต่อหัว', $summary['cost_per_pax']],
            ['จุดคุ้มทุน (ที่นั่ง)', $summary['break_even_pax']],
            ['งบที่ตั้งไว้', $summary['budget']],
            [],
            ['วันที่', 'ประเภท', 'หมวด', 'รายการ', 'จำนวนเงิน', 'สลิป', 'ผู้บันทึก', 'หมายเหตุ'],
        ];

        foreach ($schedule->expenses as $expense) {
            $lines[] = [
                $expense->spent_at?->toDateString() ?? $expense->created_at?->toDateString(),
                $expense->isIncome() ? 'รายรับ' : 'รายจ่าย',
                $expense->categoryLabel() ?? '-',
                $expense->name,
                (float) $expense->amount,
                $expense->slip_path ? 'มี' : 'ไม่มี',
                $expense->creator?->name ?? '-',
                $expense->note ?? '',
            ];
        }

        $csv = "\xEF\xBB\xBF".collect($lines)
            ->map(fn (array $row) => collect($row)->map(fn ($cell) => '"'.str_replace('"', '""', (string) $cell).'"')->implode(','))
            ->implode("\r\n");

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="finance-schedule-'.$schedule->id.'.csv"',
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function expensePayload(ScheduleExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_template_id' => $expense->expense_template_id,
            'kind' => $expense->kind ?: ScheduleExpense::KIND_EXPENSE,
            'category' => $expense->category,
            'category_label' => $expense->categoryLabel(),
            'name' => $expense->name,
            'amount' => (float) $expense->amount,
            'note' => $expense->note,
            // สลิปที่สตาฟถ่ายหน้างาน — signed URL อายุสั้น เหมือนสลิปโอนเงิน
            'slip_url' => MediaDisk::slipUrl($expense->slip_path),
            'has_slip' => (bool) $expense->slip_path,
            'spent_at' => $expense->spent_at?->toDateString(),
            'created_by' => $expense->created_by,
            'created_by_name' => $expense->relationLoaded('creator') ? $expense->creator?->name : null,
            'created_at' => $expense->created_at?->toIso8601String(),
        ];
    }

    /** ข้อบังคับที่หน้าเว็บต้องรู้เพื่อบอกผู้ใช้ล่วงหน้า ไม่ใช่ให้ไปเจอ error ตอนกดบันทึก */
    private function rulesPayload(): array
    {
        return [
            'strict' => SiteSettings::financeStrict(),
            'slip_required_above' => SiteSettings::financeSlipRequiredAbove(),
            'require_category' => SiteSettings::financeRequiresCategory(),
        ];
    }

    private function categoriesPayload(): array
    {
        return [
            'expense' => collect(ScheduleExpense::EXPENSE_CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()->all(),
            'income' => collect(ScheduleExpense::INCOME_CATEGORIES)->map(fn ($l, $k) => ['value' => $k, 'label' => $l])->values()->all(),
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'rounds' => 0, 'closed_rounds' => 0, 'passengers' => 0,
            'paid_revenue' => 0, 'onsite_income' => 0, 'expense_total' => 0,
            'profit' => 0, 'outstanding' => 0,
        ];
    }
}
