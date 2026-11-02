<?php

namespace App\Services;

use App\Models\BookingPassenger;
use App\Models\ExpenseTemplate;
use App\Models\ScheduleExpense;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * เงินที่สตาฟต้องจ่ายหน้างาน — ค่าผ่านด่าน ค่าเข้าอุทยาน ค่าธรรมเนียมพื้นที่ ฯลฯ
 *
 * ไม่มีตารางของตัวเอง: เป็นรายการประจำ (`expense_templates`) ที่แอดมินติ๊ก
 * "ให้สตาฟเห็น" ยอดต่อหัวคูณจำนวนคนของรอบให้ตรงนี้ที่เดียว และเมื่อสตาฟกด
 * "จ่ายแล้ว" ก็ลงสมุดบัญชีหน้างานเป็นแถวที่อ้าง template เดิม — ปุ่ม
 * "ใช้รายการประจำ" ของแอดมินจึงข้ามรายการนี้เอง ไม่เกิดยอดซ้ำ
 */
class StaffFeeService
{
    public function __construct(private ScheduleLedgerService $ledgerService) {}

    /**
     * คนที่ใช้คูณ — ลูกทริปที่จองยืนยันแล้วและไม่ได้แจ้งว่าไม่ไป + ทีมงานประจำรอบ
     *
     * คนที่แจ้ง "ไปไม่ได้" ไม่ได้ข้ามด่าน/เข้าพื้นที่ จึงไม่นับ ต่างจากใบซื้อของ
     * ที่นับทุกที่นั่ง (ของกินเผื่อไว้ไม่เสียหาย แต่เงินค่าผ่านด่านต้องพอดี)
     *
     * @return array{travellers: int, staff: int}
     */
    public function headcount(TripSchedule $schedule): array
    {
        $travellers = BookingPassenger::whereHas('booking', fn ($q) => $q
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', ['confirmed', 'completed']))
            ->where(fn ($q) => $q->whereNull('not_going_at')->orWhereNotNull('checked_in_at'))
            ->count();

        return ['travellers' => $travellers, 'staff' => $schedule->activeStaff()->count()];
    }

    /** ยอดของรายการประจำหนึ่งรายการในรอบนี้ — ใช้ร่วมกับฝั่งแอดมิน */
    public function amountFor(ExpenseTemplate $template, TripSchedule $schedule, ?array $headcount = null): ?float
    {
        $headcount ??= $template->isPerHead() ? $this->headcount($schedule) : ['travellers' => 0, 'staff' => 0];

        return $template->amountFor($headcount['travellers'], $headcount['staff']);
    }

    /**
     * รายการที่สตาฟต้องจ่ายของรอบ + สถานะว่าลงบัญชีแล้วหรือยัง
     */
    public function forSchedule(TripSchedule $schedule, ?User $viewer = null): array
    {
        $templates = $this->staffTemplates($schedule);
        $headcount = $this->headcount($schedule);
        $entries = $this->recordedEntries($schedule, $templates);

        $items = $templates->map(function (ExpenseTemplate $t) use ($headcount, $entries, $viewer) {
            return $this->presentItem($t, $headcount, $entries->get($t->id), $viewer);
        })->values();

        $expected = round((float) $items->sum(fn ($i) => $i['amount'] ?? 0), 2);
        $paid = $items->filter(fn ($i) => $i['paid']);
        $paidTotal = round((float) $paid->sum(fn ($i) => $i['entry']['amount']), 2);
        // ยอดที่ยังต้องเตรียม = รายการที่ยังไม่จ่ายเท่านั้น (จ่ายจริงต่างจากที่ตั้งไว้ก็ไม่ไปหักลบ)
        $remaining = round((float) $items->reject(fn ($i) => $i['paid'])->sum(fn ($i) => $i['amount'] ?? 0), 2);

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'finance_closed' => $schedule->financeClosed(),
            ],
            'headcount' => $headcount,
            'rules' => [
                'slip_required_above' => SiteSettings::financeSlipRequiredAbove(),
            ],
            'summary' => [
                'items_count' => $items->count(),
                'paid_count' => $paid->count(),
                'expected_total' => $expected,
                'paid_total' => $paidTotal,
                'remaining_total' => $remaining,
                // รายการที่แอดมินไม่ได้ตั้งยอด — ยอดรวมจึงยังไม่ครบ ต้องบอกสตาฟ
                'unpriced_count' => $items->filter(fn ($i) => $i['amount'] === null && ! $i['paid'])->count(),
            ],
            'items' => $items->all(),
        ];
    }

    /**
     * สตาฟบันทึกว่าจ่ายรายการนี้แล้ว — ลงสมุดบัญชีหน้างานหนึ่งแถว
     *
     * @throws \Exception
     */
    public function pay(TripSchedule $schedule, int $templateId, User $user, array $data, ?UploadedFile $slip = null): ScheduleExpense
    {
        $template = $this->staffTemplates($schedule)->firstWhere('id', $templateId);

        if (! $template) {
            throw new \Exception('ไม่พบรายการนี้ในทริป');
        }

        if ($this->recordedEntries($schedule, collect([$template]))->isNotEmpty()) {
            throw new \Exception('รายการนี้ลงบัญชีไปแล้ว');
        }

        $headcount = $this->headcount($schedule);
        $amount = $data['amount'] ?? $this->amountFor($template, $schedule, $headcount);

        if ($amount === null) {
            throw new \Exception('กรอกยอดที่จ่ายจริงก่อนบันทึก');
        }

        // บอกแอดมินว่ายอดนี้มาจากสูตรไหน — จ่ายจริงต่างจากที่ตั้งไว้จะได้เห็นทันที
        $note = collect([$this->ruleLabel($template, $headcount), $data['note'] ?? null])
            ->filter()
            ->implode(' · ');

        $entry = $this->ledgerService->record($schedule, $user, [
            'kind' => ScheduleExpense::KIND_EXPENSE,
            'category' => $this->categoryOf($template),
            'name' => $template->name,
            'amount' => $amount,
            'note' => $note !== '' ? $note : null,
            'spent_at' => $data['spent_at'] ?? null,
        ], $slip);

        $entry->forceFill(['expense_template_id' => $template->id])->save();

        return $entry;
    }

    /** หมวดของแถวที่จะลงบัญชี — รายการที่แอดมินไม่ได้ใส่หมวดต้องไม่ไปติดโหมดเข้มงวด */
    public function categoryOf(ExpenseTemplate $template): string
    {
        return $template->category ?: 'other';
    }

    /** @return Collection<int, ExpenseTemplate> */
    private function staffTemplates(TripSchedule $schedule): Collection
    {
        return ExpenseTemplate::where('trip_id', $schedule->trip_id)
            ->where('is_active', true)
            ->where('staff_visible', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * แถวในบัญชีของรอบที่มาจากรายการเหล่านี้ (ไม่ว่าใครลง — สตาฟหรือแอดมิน)
     *
     * @param  Collection<int, ExpenseTemplate>  $templates
     * @return Collection<int, ScheduleExpense> keyed by expense_template_id
     */
    private function recordedEntries(TripSchedule $schedule, Collection $templates): Collection
    {
        if ($templates->isEmpty()) {
            return collect();
        }

        return ScheduleExpense::with('creator:id,name')
            ->where('schedule_id', $schedule->id)
            ->whereIn('expense_template_id', $templates->pluck('id'))
            ->orderBy('id')
            ->get()
            ->unique('expense_template_id')
            ->keyBy('expense_template_id');
    }

    private function presentItem(ExpenseTemplate $t, array $headcount, ?ScheduleExpense $entry, ?User $viewer): array
    {
        $heads = $t->headsFor($headcount['travellers'], $headcount['staff']);

        return [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $this->categoryOf($t),
            'category_label' => ScheduleExpense::EXPENSE_CATEGORIES[$this->categoryOf($t)] ?? null,
            'basis' => $t->amount_basis,
            'basis_label' => ExpenseTemplate::BASES[$t->amount_basis] ?? null,
            'rate' => $t->default_amount !== null ? (float) $t->default_amount : null,
            'heads' => $heads,
            'amount' => $t->amountFor($headcount['travellers'], $headcount['staff']),
            'rule_label' => $this->ruleLabel($t, $headcount),
            'note' => $t->staff_note,
            'paid' => $entry !== null,
            'entry' => $entry ? $this->presentEntry($entry, $viewer) : null,
        ];
    }

    private function presentEntry(ScheduleExpense $e, ?User $viewer): array
    {
        return [
            'id' => $e->id,
            'amount' => (float) $e->amount,
            'spent_at' => $e->spent_at?->toDateString(),
            'created_by_name' => $e->creator?->name,
            'has_slip' => (bool) $e->slip_path,
            'slip_url' => MediaDisk::slipUrl($e->slip_path),
            // ยกเลิกได้เฉพาะคนที่ลงเอง — แถวที่แอดมินลงต้องแก้ที่หลังบ้าน
            'can_undo' => $viewer !== null && (int) $e->created_by === (int) $viewer->id,
        ];
    }

    /** "300 บาท × 12 คน (ลูกทริป 10 + ทีมงาน 2)" — สตาฟเช็กได้ว่าคิดถูกคน */
    private function ruleLabel(ExpenseTemplate $t, array $headcount): ?string
    {
        if (! $t->isPerHead()) {
            return $t->default_amount !== null ? 'เหมาต่อรอบ' : null;
        }

        $who = $t->amount_basis === ExpenseTemplate::BASIS_PER_PERSON
            ? "ลูกทริป {$headcount['travellers']} + ทีมงาน {$headcount['staff']}"
            : 'ลูกทริป';
        $heads = $t->headsFor($headcount['travellers'], $headcount['staff']);

        if ($t->default_amount === null) {
            return "{$heads} คน ({$who}) · ยังไม่ได้ตั้งยอดต่อคน";
        }

        return $this->money((float) $t->default_amount)." บาท × {$heads} คน ({$who})";
    }

    private function money(float $n): string
    {
        return fmod($n, 1.0) === 0.0 ? number_format($n) : number_format($n, 2);
    }
}
