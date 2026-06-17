<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFinanceController extends Controller
{
    use ApiResponse;

    /**
     * Bookings ที่นับเป็นรายรับ — ตัด cancelled/expired ออก
     * "เงินที่รับจริง" = paid_amount − refund_amount
     */
    private const REVENUE_STATUSES_EXCLUDED = ['cancelled', 'expired'];

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

        // รายรับต่อทริป
        $revenue = $applyDate(
            Booking::query()
                ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
                ->whereNotIn('bookings.status', self::REVENUE_STATUSES_EXCLUDED)
        )
            ->selectRaw('trip_schedules.trip_id as trip_id, SUM(bookings.paid_amount) as paid, SUM(bookings.refund_amount) as refunded, COUNT(bookings.id) as bookings_count')
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

        // ค่าใช้จ่ายต่อทริป
        $expenses = $applyDate(
            ScheduleExpense::query()
                ->join('trip_schedules', 'schedule_expenses.schedule_id', '=', 'trip_schedules.id')
        )
            ->selectRaw('trip_schedules.trip_id as trip_id, SUM(schedule_expenses.amount) as expenses, COUNT(schedule_expenses.id) as items_count')
            ->groupBy('trip_schedules.trip_id')
            ->get()
            ->keyBy('trip_id');

        $tripIds = $revenue->keys()->merge($expenses->keys())->unique();
        $trips = Trip::whereIn('id', $tripIds)->get(['id', 'title', 'type'])->keyBy('id');

        $rows = $tripIds->map(function ($tripId) use ($trips, $revenue, $expenses, $pax) {
            $rev = $revenue->get($tripId);
            $exp = $expenses->get($tripId);

            $paidRevenue = round((float) ($rev->paid ?? 0) - (float) ($rev->refunded ?? 0), 2);
            $expenseTotal = round((float) ($exp->expenses ?? 0), 2);
            $profit = round($paidRevenue - $expenseTotal, 2);

            return [
                'trip_id' => (int) $tripId,
                'title' => $trips->get($tripId)?->title ?? 'ไม่ทราบ',
                'type' => $trips->get($tripId)?->type,
                'bookings_count' => (int) ($rev->bookings_count ?? 0),
                'passengers_count' => (int) ($pax->get($tripId) ?? 0),
                'paid_revenue' => $paidRevenue,
                'expense_total' => $expenseTotal,
                'expense_items_count' => (int) ($exp->items_count ?? 0),
                'profit' => $profit,
                'margin_percent' => $paidRevenue > 0 ? round($profit / $paidRevenue * 100, 1) : null,
            ];
        })->sortByDesc('profit')->values();

        $summary = [
            'period' => $from || $to ? trim(($from ?? '…').' ถึง '.($to ?? '…')) : 'ทั้งหมด',
            'trips_count' => $rows->count(),
            'paid_revenue' => round($rows->sum('paid_revenue'), 2),
            'expense_total' => round($rows->sum('expense_total'), 2),
            'profit' => round($rows->sum('profit'), 2),
        ];

        return $this->success([
            'summary' => $summary,
            'trips' => $rows,
        ]);
    }

    // ─── สรุปกำไรรายรอบเดินทางของทริปหนึ่ง ───────────────────────

    public function tripScheduleProfit(int $tripId): JsonResponse
    {
        $trip = Trip::findOrFail($tripId);

        $schedules = $trip->schedules()
            ->with(['expenses.creator:id,name'])
            ->orderByDesc('departure_date')
            ->get();

        $scheduleIds = $schedules->pluck('id');

        $revenue = Booking::whereIn('schedule_id', $scheduleIds)
            ->whereNotIn('status', self::REVENUE_STATUSES_EXCLUDED)
            ->selectRaw('schedule_id, SUM(paid_amount) as paid, SUM(refund_amount) as refunded, COUNT(*) as bookings_count')
            ->groupBy('schedule_id')
            ->get()
            ->keyBy('schedule_id');

        $pax = BookingPassenger::query()
            ->join('bookings', 'booking_passengers.booking_id', '=', 'bookings.id')
            ->whereIn('bookings.schedule_id', $scheduleIds)
            ->whereNotIn('bookings.status', self::REVENUE_STATUSES_EXCLUDED)
            ->selectRaw('bookings.schedule_id as schedule_id, COUNT(booking_passengers.id) as pax')
            ->groupBy('bookings.schedule_id')
            ->pluck('pax', 'schedule_id');

        $rows = $schedules->map(function (TripSchedule $schedule) use ($revenue, $pax) {
            $rev = $revenue->get($schedule->id);

            $paidRevenue = round((float) ($rev->paid ?? 0) - (float) ($rev->refunded ?? 0), 2);
            $expenseTotal = round((float) $schedule->expenses->sum('amount'), 2);
            $profit = round($paidRevenue - $expenseTotal, 2);

            return [
                'schedule_id' => $schedule->id,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_label' => $schedule->departureLabelThai(),
                'status' => $schedule->status,
                'bookings_count' => (int) ($rev->bookings_count ?? 0),
                'passengers_count' => (int) ($pax->get($schedule->id) ?? 0),
                'paid_revenue' => $paidRevenue,
                'expense_total' => $expenseTotal,
                'profit' => $profit,
                'margin_percent' => $paidRevenue > 0 ? round($profit / $paidRevenue * 100, 1) : null,
                'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            ];
        })->values();

        return $this->success([
            'trip' => ['id' => $trip->id, 'title' => $trip->title, 'type' => $trip->type],
            'totals' => [
                'paid_revenue' => round($rows->sum('paid_revenue'), 2),
                'expense_total' => round($rows->sum('expense_total'), 2),
                'profit' => round($rows->sum('profit'), 2),
            ],
            'schedules' => $rows,
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
        $schedule = TripSchedule::with('expenses.creator:id,name')->findOrFail($scheduleId);

        return $this->success([
            'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            'summary' => $this->scheduleSummary($schedule),
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
        ]);

        $name = $validated['name'] ?? null;
        $amount = $validated['amount'] ?? null;
        $templateId = null;

        if (! empty($validated['expense_template_id'])) {
            // รายการประจำต้องเป็นของทริปเดียวกับรอบนี้
            $template = ExpenseTemplate::where('trip_id', $schedule->trip_id)
                ->findOrFail($validated['expense_template_id']);
            $templateId = $template->id;
            $name = $name ?: $template->name;
            $amount = $amount ?? $template->default_amount;
        }

        $expense = $schedule->expenses()->create([
            'expense_template_id' => $templateId,
            'name' => $name,
            'amount' => $amount ?? 0,
            'note' => $validated['note'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return $this->success([
            'expense' => $this->expensePayload($expense),
            'summary' => $this->scheduleSummary($schedule->fresh('expenses')),
        ], 'เพิ่มค่าใช้จ่ายสำเร็จ', 201);
    }

    public function applyTemplates(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

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

            $schedule->expenses()->create([
                'expense_template_id' => $template->id,
                'name' => $template->name,
                'amount' => $template->default_amount ?? 0,
                'created_by' => $request->user()?->id,
            ]);
            $created++;
        }

        $schedule->load('expenses.creator:id,name');

        return $this->success([
            'created' => $created,
            'expenses' => $schedule->expenses->map(fn (ScheduleExpense $e) => $this->expensePayload($e))->values(),
            'summary' => $this->scheduleSummary($schedule),
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

        $created = 0;
        foreach ($targets as $target) {
            foreach ($sourceExpenses as $expense) {
                $target->expenses()->create([
                    'expense_template_id' => null,
                    'name' => $expense->name,
                    'amount' => $expense->amount,
                    'note' => $expense->note,
                    'created_by' => $request->user()?->id,
                ]);
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

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $expense->update($validated);

        return $this->success([
            'expense' => $this->expensePayload($expense->fresh('creator')),
            'summary' => $this->scheduleSummary($expense->schedule()->with('expenses')->first()),
        ], 'แก้ไขค่าใช้จ่ายสำเร็จ');
    }

    public function deleteExpense(int $scheduleId, int $id): JsonResponse
    {
        $expense = ScheduleExpense::where('schedule_id', $scheduleId)->findOrFail($id);
        $expense->delete();

        return $this->success([
            'summary' => $this->scheduleSummary(TripSchedule::with('expenses')->find($scheduleId)),
        ], 'ลบค่าใช้จ่ายสำเร็จ');
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function expensePayload(ScheduleExpense $expense): array
    {
        return [
            'id' => $expense->id,
            'expense_template_id' => $expense->expense_template_id,
            'name' => $expense->name,
            'amount' => (float) $expense->amount,
            'note' => $expense->note,
            'created_by' => $expense->created_by,
            'created_by_name' => $expense->relationLoaded('creator') ? $expense->creator?->name : null,
            'created_at' => $expense->created_at?->toIso8601String(),
        ];
    }

    private function scheduleSummary(TripSchedule $schedule): array
    {
        $rev = Booking::where('schedule_id', $schedule->id)
            ->whereNotIn('status', self::REVENUE_STATUSES_EXCLUDED)
            ->selectRaw('SUM(paid_amount) as paid, SUM(refund_amount) as refunded, COUNT(*) as bookings_count')
            ->first();

        $paidRevenue = round((float) ($rev->paid ?? 0) - (float) ($rev->refunded ?? 0), 2);
        $expenseTotal = round((float) $schedule->expenses->sum('amount'), 2);
        $profit = round($paidRevenue - $expenseTotal, 2);

        return [
            'schedule_id' => $schedule->id,
            'bookings_count' => (int) ($rev->bookings_count ?? 0),
            'paid_revenue' => $paidRevenue,
            'expense_total' => $expenseTotal,
            'profit' => $profit,
            'margin_percent' => $paidRevenue > 0 ? round($profit / $paidRevenue * 100, 1) : null,
        ];
    }
}
