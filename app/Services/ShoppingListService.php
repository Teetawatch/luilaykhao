<?php

namespace App\Services;

use App\Jobs\NotifyShoppingReport;
use App\Models\BookingPassenger;
use App\Models\ScheduleExpense;
use App\Models\ScheduleShoppingItem;
use App\Models\ScheduleShoppingReport;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\TripShoppingItem;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ใบซื้อของก่อนออกทริป — ทุกรอบสตาฟต้องแวะซื้อน้ำ น้ำแข็ง ของกิน ฯลฯ
 * แต่ความรู้ว่าต้องซื้ออะไรบ้างเคยอยู่ในหัวคนที่เคยไปเท่านั้น
 *
 * ไหลแบบนี้:
 *  1. แอดมินตั้ง "รายการประจำทริป" ครั้งเดียว (trip_shopping_items)
 *  2. เปิดใบซื้อของของรอบครั้งแรก → ก๊อปรายการประจำทริปมาเป็นของรอบนั้น
 *  3. สตาฟติ๊กทีละอย่างที่ซื้อแล้ว (สถานะของทั้งทีม ใครติ๊กก็เห็นกันหมด)
 *     เพิ่มของเฉพาะรอบได้ถ้าหน้างานต้องซื้ออะไรเพิ่ม
 *  4. ส่งรายงานปิดท้าย บังคับแนบรูป → แอดมินได้แจ้งเตือน + การ์ดในคิวงาน
 *     ใส่ยอดเงินด้วยได้ ระบบลงบัญชีหน้างานให้เลย ไม่ต้องจดซ้ำอีกหน้า
 *
 * ส่งแล้วใบจะล็อก จนกว่าแอดมินจะตีกลับให้แก้
 */
class ShoppingListService
{
    /** รูปเก็บบนดิสก์ private — path ต้องขึ้นต้นด้วย slips/ ไม่งั้น slipUrl คืน null */
    private const PHOTO_DIR = 'slips/shopping';

    /** แถวในบัญชีหน้างานที่สร้างจากยอดซื้อของ */
    public const LEDGER_NAME = 'ซื้อของก่อนออกทริป';

    public const MAX_PHOTOS_PER_SUBMIT = 6;

    public function __construct(
        private ScheduleLedgerService $ledgerService,
        private ScheduleFinanceService $financeService,
    ) {}

    // ─── รายการประจำทริป ────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    public function templateFor(Trip $trip): array
    {
        return $trip->shoppingItems()->get()
            ->map(fn (TripShoppingItem $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'quantity' => $i->quantity,
                'unit' => $i->unit,
                'per_person' => $i->per_person,
                'note' => $i->note,
            ])
            ->values()
            ->all();
    }

    /**
     * แทนรายการประจำทริปทั้งชุดด้วยลิสต์ที่ส่งมา (ลำดับในลิสต์ = ลำดับที่แสดง)
     *
     * แถวที่มี id เดิมจะถูกแก้ในที่ ไม่ลบแล้วสร้างใหม่ — รอบที่ก๊อปไปแล้วยังผูก
     * template_item_id กับแถวเดิมอยู่ ปุ่ม "ดึงรายการจากทริปอีกครั้ง" จึงรู้ว่า
     * อันไหนคืออันเดียวกัน
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function saveTemplate(Trip $trip, array $rows): array
    {
        DB::transaction(function () use ($trip, $rows) {
            $existing = $trip->shoppingItems()->get()->keyBy('id');
            $keep = [];

            foreach (array_values($rows) as $index => $row) {
                $attrs = $this->itemAttributes($row) + ['sort_order' => $index];
                $item = isset($row['id']) ? $existing->get((int) $row['id']) : null;

                if ($item) {
                    $item->update($attrs);
                } else {
                    $item = $trip->shoppingItems()->create($attrs);
                }

                $keep[] = $item->id;
            }

            $trip->shoppingItems()->whereNotIn('id', $keep)->delete();
        });

        return $this->templateFor($trip);
    }

    // ─── ใบซื้อของของรอบ ───────────────────────────────────────

    /**
     * ก๊อปรายการประจำทริปมาเป็นของรอบ — ครั้งเดียวต่อรอบ
     *
     * ทริปที่ยังไม่มีรายการประจำจะยังไม่ถูกนับว่าก๊อปแล้ว พอแอดมินตั้งรายการ
     * ทีหลัง รอบที่ยังไม่ออกเดินทางจะได้รายการตามไปเองตอนเปิดดูครั้งถัดไป
     */
    public function ensureSeeded(TripSchedule $schedule): void
    {
        if ($schedule->shopping_seeded_at !== null) {
            return;
        }

        DB::transaction(function () use ($schedule) {
            $fresh = TripSchedule::whereKey($schedule->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->shopping_seeded_at !== null) {
                return;
            }

            $template = TripShoppingItem::where('trip_id', $fresh->trip_id)
                ->orderBy('sort_order')->orderBy('id')->get();

            if ($template->isEmpty()) {
                return;
            }

            foreach ($template as $t) {
                $this->copyFromTemplate($fresh, $t);
            }

            $fresh->forceFill(['shopping_seeded_at' => now()])->save();
        });

        $schedule->refresh();
    }

    /**
     * ดึงรายการประจำทริปมาอีกครั้ง — เพิ่มของที่ยังไม่มี และปรับของที่ยังไม่ได้ซื้อ
     * ให้ตรงกับทริป ของที่ติ๊กซื้อไปแล้วไม่แตะ (สตาฟซื้อตามที่เห็นตอนนั้นไปแล้ว)
     *
     * @return array{added: int, updated: int}
     */
    public function resync(TripSchedule $schedule): array
    {
        $added = 0;
        $updated = 0;

        DB::transaction(function () use ($schedule, &$added, &$updated) {
            $template = TripShoppingItem::where('trip_id', $schedule->trip_id)
                ->orderBy('sort_order')->orderBy('id')->get();
            $current = ScheduleShoppingItem::where('schedule_id', $schedule->id)
                ->whereNotNull('template_item_id')
                ->get()
                ->keyBy('template_item_id');

            foreach ($template as $t) {
                $item = $current->get($t->id);

                if (! $item) {
                    $this->copyFromTemplate($schedule, $t);
                    $added++;

                    continue;
                }

                if ($item->bought_at === null) {
                    $item->fill($this->itemAttributes($t->toArray()) + ['sort_order' => $t->sort_order]);

                    if ($item->isDirty()) {
                        $item->save();
                        $updated++;
                    }
                }
            }

            $schedule->forceFill(['shopping_seeded_at' => $schedule->shopping_seeded_at ?? now()])->save();
        });

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * ใบซื้อของทั้งใบ พร้อมยอดต่อคนที่คูณจำนวนคนแล้ว
     *
     * @param  bool  $asAdmin  แอดมินแก้/ลบได้ทุกแถวและทุกเวลา สตาฟลบได้แค่ของที่ตัวเองเพิ่ม
     */
    public function payload(TripSchedule $schedule, ?User $viewer = null, bool $asAdmin = false): array
    {
        $this->ensureSeeded($schedule);
        $schedule->loadMissing('trip');

        $report = ScheduleShoppingReport::with(['submittedBy:id,name', 'reviewedBy:id,name', 'reopenedBy:id,name'])
            ->where('schedule_id', $schedule->id)
            ->first();
        $locked = (bool) $report?->isSubmitted();

        // ส่งแล้วใช้จำนวนคนตอนส่ง ยอด "ต่อคน" ในรายงานจะได้ไม่ขยับตามการจองทีหลัง
        $headcount = $this->headcount($schedule);
        $count = $locked ? $report->headcount : $headcount['total'];

        $items = ScheduleShoppingItem::with(['boughtBy:id,name', 'addedBy:id,name'])
            ->where('schedule_id', $schedule->id)
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        $bought = $items->whereNotNull('bought_at')->count();

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_id' => $schedule->trip_id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_date_thai' => ThaiDate::short($schedule->departure_date),
            ],
            'headcount' => $headcount + ['used' => $count],
            'has_template' => TripShoppingItem::where('trip_id', $schedule->trip_id)->exists(),
            'summary' => [
                'total_items' => $items->count(),
                'bought_items' => $bought,
                'remaining_items' => $items->count() - $bought,
            ],
            'locked' => $locked,
            'max_photos' => self::MAX_PHOTOS_PER_SUBMIT,
            'ledger' => [
                // ปิดงบแล้วลงบัญชีหน้างานไม่ได้ แอปจะได้ซ่อนช่องยอดเงินแทนการเด้ง error
                'finance_closed' => $schedule->financeClosed(),
                'category' => 'food',
            ],
            'items' => $items
                ->map(fn (ScheduleShoppingItem $i) => $this->presentItem($i, $count, $viewer, $asAdmin, $locked))
                ->values()
                ->all(),
            'report' => $report ? $this->presentReport($report, $items) : null,
        ];
    }

    /**
     * จำนวนคนในรอบที่ใช้คูณของ "ต่อคน" — ลูกค้าที่จองยืนยันแล้ว + ทีมงานประจำรอบ
     * (น้ำ/ของกินต้องพอสำหรับทีมงานด้วย)
     *
     * @return array{travellers: int, staff: int, total: int}
     */
    public function headcount(TripSchedule $schedule): array
    {
        $travellers = BookingPassenger::whereHas('booking', fn ($q) => $q
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', ['confirmed', 'completed']))
            ->count();
        $staff = $schedule->activeStaff()->count();

        return ['travellers' => $travellers, 'staff' => $staff, 'total' => $travellers + $staff];
    }

    /**
     * ติ๊ก/เลิกติ๊ก "ซื้อแล้ว"
     *
     * @throws \Exception
     */
    public function setBought(TripSchedule $schedule, int $itemId, User $user, bool $bought): void
    {
        $this->assertOpen($schedule);
        $item = $this->findItem($schedule, $itemId);

        $item->forceFill([
            'bought_at' => $bought ? ($item->bought_at ?? now()) : null,
            'bought_by_id' => $bought ? ($item->bought_by_id ?? $user->id) : null,
        ])->save();
    }

    /**
     * เพิ่มของเฉพาะรอบนี้ — ไม่แตะรายการประจำทริป
     *
     * @throws \Exception
     */
    public function addItem(TripSchedule $schedule, User $user, array $data, bool $asAdmin = false): ScheduleShoppingItem
    {
        if (! $asAdmin) {
            $this->assertOpen($schedule);
        }

        $this->ensureSeeded($schedule);

        return ScheduleShoppingItem::create($this->itemAttributes($data) + [
            'schedule_id' => $schedule->id,
            'sort_order' => (int) ScheduleShoppingItem::where('schedule_id', $schedule->id)->max('sort_order') + 1,
            'added_by_id' => $user->id,
        ]);
    }

    /** แอดมินแก้ของในใบของรอบ (ไม่ย้อนไปแก้รายการประจำทริป) */
    public function updateItem(TripSchedule $schedule, int $itemId, array $data): ScheduleShoppingItem
    {
        $item = $this->findItem($schedule, $itemId);
        $item->update($this->itemAttributes($data + $item->only(['name', 'quantity', 'unit', 'per_person', 'note'])));

        return $item;
    }

    /**
     * ลบของออกจากใบของรอบ — สตาฟลบได้เฉพาะของที่ตัวเองเพิ่ม และก่อนส่งรายงาน
     *
     * @throws \Exception
     */
    public function deleteItem(TripSchedule $schedule, int $itemId, User $user, bool $asAdmin = false): void
    {
        $item = $this->findItem($schedule, $itemId);

        if (! $asAdmin) {
            $this->assertOpen($schedule);

            if ($item->template_item_id !== null || (int) $item->added_by_id !== (int) $user->id) {
                throw new \Exception('ลบได้เฉพาะของที่คุณเพิ่มเอง — ของในรายการประจำทริปให้เขียนหมายเหตุตอนส่งรายงานแทน');
            }
        }

        $item->delete();
    }

    // ─── รายงาน ────────────────────────────────────────────────

    /**
     * ส่งรายงานปิดท้าย — บังคับรูปอย่างน้อยหนึ่งรูป ของที่ยังไม่ติ๊กต้องมีหมายเหตุ
     *
     * @param  array<int, UploadedFile>  $photos
     * @param  array{total_amount?: float|null, note?: string|null, add_to_ledger?: bool}  $data
     *
     * @throws \Exception
     */
    public function submit(TripSchedule $schedule, User $user, array $photos, array $data): ScheduleShoppingReport
    {
        $this->ensureSeeded($schedule);
        $this->assertOpen($schedule);

        if (count($photos) === 0) {
            throw new \Exception('ต้องถ่ายรูปของที่ซื้อหรือใบเสร็จอย่างน้อย 1 รูปก่อนส่งรายงาน');
        }

        if (count($photos) > self::MAX_PHOTOS_PER_SUBMIT) {
            throw new \Exception('แนบรูปได้ไม่เกิน '.self::MAX_PHOTOS_PER_SUBMIT.' รูปต่อครั้ง');
        }

        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $remaining = ScheduleShoppingItem::where('schedule_id', $schedule->id)->whereNull('bought_at')->count();

        // ซื้อไม่ครบไม่ผิด (บางร้านไม่มีของ) แต่แอดมินต้องรู้ว่าเพราะอะไร
        if ($remaining > 0 && $note === null) {
            throw new \Exception("ยังมีของที่ไม่ได้ติ๊ก {$remaining} รายการ — ช่วยเขียนหมายเหตุบอกแอดมินด้วยว่าเพราะอะไร");
        }

        $amount = isset($data['total_amount']) && $data['total_amount'] !== ''
            ? round((float) $data['total_amount'], 2)
            : null;
        $toLedger = ($data['add_to_ledger'] ?? false) && $amount !== null && $amount > 0;

        $report = ScheduleShoppingReport::firstOrNew(['schedule_id' => $schedule->id]);
        $expense = $report->expense_id ? ScheduleExpense::find($report->expense_id) : null;

        // เช็กกติกาบัญชีก่อนเซฟไฟล์ใด ๆ — ติดกติกาแล้วไม่ควรเหลือรูปกำพร้าบน bucket
        if ($toLedger) {
            $this->financeService->assertEditable($schedule, $user);
            $this->financeService->assertEvidence([
                'kind' => ScheduleExpense::KIND_EXPENSE,
                'category' => 'food',
                'amount' => $amount,
            ], true);
        }

        $paths = array_values(array_filter(array_map(fn (UploadedFile $f) => $this->storePhoto($f), $photos)));

        if ($paths === []) {
            throw new \Exception('อัปโหลดรูปไม่สำเร็จ ลองส่งใหม่อีกครั้ง');
        }

        if ($toLedger) {
            $ledgerData = [
                'kind' => ScheduleExpense::KIND_EXPENSE,
                'category' => 'food',
                'name' => self::LEDGER_NAME,
                'amount' => $amount,
                'note' => 'ลงอัตโนมัติจากรายงานซื้อของ',
            ];

            // ส่งซ้ำหลังโดนตีกลับ = แก้แถวเดิม ไม่ลงรายจ่ายซ้ำสองแถว
            $expense = $expense
                ? $this->ledgerService->update($expense, $ledgerData, $photos[0], $user)
                : $this->ledgerService->record($schedule, $user, $ledgerData, $photos[0]);
        }

        $report->fill([
            'submitted_at' => now(),
            'submitted_by_id' => $user->id,
            'headcount' => $this->headcount($schedule)['total'],
            'total_amount' => $amount,
            'note' => $note,
            // สะสม ไม่ทับ — รูปจากรอบก่อนถูกตีกลับยังเป็นหลักฐานอยู่
            'photos' => array_values(array_merge($report->photos ?? [], $paths)),
            'expense_id' => $expense?->id,
            // ส่งใหม่ = ต้องให้แอดมินดูใหม่
            'reviewed_at' => null,
            'reviewed_by_id' => null,
        ])->save();

        NotifyShoppingReport::dispatchAfterResponse($report->id, NotifyShoppingReport::SUBMITTED);

        return $report;
    }

    /**
     * แอดมินตีกลับให้แก้ — ใบซื้อของกลับมาติ๊กได้ และสตาฟคนที่ส่งได้แจ้งเตือน
     *
     * @throws \Exception
     */
    public function reopen(TripSchedule $schedule, User $admin, string $reason): ScheduleShoppingReport
    {
        $report = ScheduleShoppingReport::where('schedule_id', $schedule->id)->first();

        if (! $report?->isSubmitted()) {
            throw new \Exception('รอบนี้ยังไม่ได้ส่งรายงานซื้อของ');
        }

        $report->forceFill([
            'submitted_at' => null,
            'reviewed_at' => null,
            'reviewed_by_id' => null,
            'reopened_at' => now(),
            'reopened_by_id' => $admin->id,
            'reopen_reason' => trim($reason),
        ])->save();

        NotifyShoppingReport::dispatchAfterResponse($report->id, NotifyShoppingReport::REOPENED);

        return $report;
    }

    /**
     * แอดมินรับทราบรายงานแล้ว — ใช้ปลดการ์ดออกจากคิวงาน
     *
     * @throws \Exception
     */
    public function acknowledge(TripSchedule $schedule, User $admin): ScheduleShoppingReport
    {
        $report = ScheduleShoppingReport::where('schedule_id', $schedule->id)->first();

        if (! $report?->isSubmitted()) {
            throw new \Exception('รอบนี้ยังไม่ได้ส่งรายงานซื้อของ');
        }

        $report->forceFill(['reviewed_at' => now(), 'reviewed_by_id' => $admin->id])->save();

        return $report;
    }

    // ─── หน้ารวมของแอดมิน ──────────────────────────────────────

    /**
     * รอบทั้งหมดพร้อมสถานะใบซื้อของ — ค่าตั้งต้นเอาเฉพาะรอบที่ยังไม่จบทริป
     * (เผื่อ 3 วันหลังกลับ ให้แอดมินยังตามรายงานของรอบที่เพิ่งจบได้)
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminRounds(bool $includePast = false): array
    {
        $today = Carbon::parse(now('Asia/Bangkok')->toDateString());

        $query = TripSchedule::with(['trip:id,title,cover_image', 'shoppingReport'])
            ->withCount([
                'shoppingItems as items_count',
                'shoppingItems as bought_count' => fn ($q) => $q->whereNotNull('bought_at'),
            ])
            ->where('status', '!=', 'cancelled');

        if ($includePast) {
            $query->orderByDesc('departure_date')->limit(150);
        } else {
            $query->whereRaw('COALESCE(return_date, departure_date) >= ?', [$today->copy()->subDays(3)->toDateString()])
                ->orderBy('departure_date');
        }

        $rounds = $query->get();
        $templateCounts = TripShoppingItem::whereIn('trip_id', $rounds->pluck('trip_id')->unique())
            ->selectRaw('trip_id, COUNT(*) as c')
            ->groupBy('trip_id')
            ->pluck('c', 'trip_id');

        return $rounds->map(function (TripSchedule $s) use ($templateCounts, $today) {
            $report = $s->shoppingReport;
            // ยังไม่มีใครเปิดใบ = ยังไม่ได้ก๊อป ใช้จำนวนของรายการประจำทริปแทน
            $items = $s->shopping_seeded_at !== null || $s->items_count > 0
                ? (int) $s->items_count
                : (int) ($templateCounts[$s->trip_id] ?? 0);

            return [
                'id' => $s->id,
                'trip_id' => $s->trip_id,
                'trip_title' => $s->trip?->title,
                'cover_image' => $s->trip?->cover_image,
                'departure_date' => $s->departure_date?->toDateString(),
                'departure_date_thai' => ThaiDate::short($s->departure_date),
                'days_left' => $s->departure_date ? (int) $today->diffInDays($s->departure_date, false) : null,
                'items_count' => $items,
                'bought_count' => (int) $s->bought_count,
                'status' => $this->roundStatus($report, $items, (int) $s->bought_count),
                'submitted_at' => $report?->submitted_at?->toIso8601String(),
                'total_amount' => $report?->total_amount,
            ];
        })->values()->all();
    }

    /** รายงานที่ส่งมาแล้วแต่แอดมินยังไม่ได้เปิดดู — ใช้ในคิวงาน */
    public function unreviewedQuery()
    {
        return ScheduleShoppingReport::whereNotNull('submitted_at')->whereNull('reviewed_at');
    }

    // ─── ภายใน ─────────────────────────────────────────────────

    private function roundStatus(?ScheduleShoppingReport $report, int $items, int $bought): string
    {
        return match (true) {
            $report?->isSubmitted() && $report->reviewed_at !== null => 'reviewed',
            (bool) $report?->isSubmitted() => 'submitted',
            $report?->reopened_at !== null => 'reopened',
            $bought > 0 => 'in_progress',
            $items > 0 => 'not_started',
            default => 'no_list',
        };
    }

    /** @throws \Exception */
    private function assertOpen(TripSchedule $schedule): void
    {
        $submitted = ScheduleShoppingReport::where('schedule_id', $schedule->id)
            ->whereNotNull('submitted_at')
            ->exists();

        if ($submitted) {
            throw new \Exception('ส่งรายงานซื้อของของรอบนี้ไปแล้ว — ถ้าต้องแก้ ให้แอดมินตีกลับก่อน');
        }
    }

    /** @throws \Exception */
    private function findItem(TripSchedule $schedule, int $itemId): ScheduleShoppingItem
    {
        $item = ScheduleShoppingItem::where('schedule_id', $schedule->id)->find($itemId);

        if (! $item) {
            throw new \Exception('ไม่พบรายการนี้ในใบซื้อของของรอบ');
        }

        return $item;
    }

    private function copyFromTemplate(TripSchedule $schedule, TripShoppingItem $t): ScheduleShoppingItem
    {
        return ScheduleShoppingItem::create($this->itemAttributes($t->toArray()) + [
            'schedule_id' => $schedule->id,
            'template_item_id' => $t->id,
            'sort_order' => $t->sort_order,
        ]);
    }

    /** @return array{name: string, quantity: float, unit: ?string, per_person: bool, note: ?string} */
    private function itemAttributes(array $row): array
    {
        $unit = trim((string) ($row['unit'] ?? ''));
        $note = trim((string) ($row['note'] ?? ''));
        $quantity = (float) ($row['quantity'] ?? 1);

        return [
            'name' => trim((string) ($row['name'] ?? '')),
            'quantity' => $quantity > 0 ? round($quantity, 2) : 1,
            'unit' => $unit !== '' ? $unit : null,
            'per_person' => (bool) ($row['per_person'] ?? false),
            'note' => $note !== '' ? $note : null,
        ];
    }

    private function presentItem(ScheduleShoppingItem $i, int $headcount, ?User $viewer, bool $asAdmin, bool $locked): array
    {
        $total = $i->per_person ? (float) ceil($i->quantity * $headcount) : $i->quantity;
        $unit = $i->unit ? ' '.$i->unit : '';
        $isExtra = $i->template_item_id === null;

        return [
            'id' => $i->id,
            'name' => $i->name,
            'quantity' => $i->quantity,
            'unit' => $i->unit,
            'per_person' => $i->per_person,
            'total_quantity' => $total,
            // ข้อความพร้อมแสดง — แอปและเว็บไม่ต้องคิดคำเอง
            'total_label' => $this->number($total).$unit,
            'rule_label' => $i->per_person
                ? $this->number($i->quantity).$unit.'/คน × '.$headcount.' คน'
                : null,
            'note' => $i->note,
            'source' => $isExtra ? 'extra' : 'template',
            'added_by_name' => $isExtra ? $i->addedBy?->name : null,
            'bought' => $i->bought_at !== null,
            'bought_at' => $i->bought_at?->toIso8601String(),
            'bought_by_name' => $i->boughtBy?->name,
            'can_delete' => $asAdmin || (! $locked && $isExtra && $viewer !== null
                && (int) $i->added_by_id === (int) $viewer->id),
        ];
    }

    /** @param  Collection<int, ScheduleShoppingItem>  $items */
    private function presentReport(ScheduleShoppingReport $r, Collection $items): array
    {
        return [
            'submitted' => $r->isSubmitted(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'submitted_at_label' => $r->submitted_at
                ? ThaiDate::shortTime($r->submitted_at->copy()->timezone('Asia/Bangkok'))
                : null,
            'submitted_by_name' => $r->submittedBy?->name,
            'headcount' => $r->headcount,
            'total_amount' => $r->total_amount,
            'note' => $r->note,
            'photos' => collect($r->photos ?? [])
                ->map(fn ($path) => MediaDisk::slipUrl($path))
                ->filter()
                ->values()
                ->all(),
            'in_ledger' => $r->expense_id !== null,
            'unbought' => $items->whereNull('bought_at')->pluck('name')->values()->all(),
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'reviewed_by_name' => $r->reviewedBy?->name,
            'reopened_at' => $r->reopened_at?->toIso8601String(),
            'reopened_by_name' => $r->reopenedBy?->name,
            'reopen_reason' => $r->reopen_reason,
        ];
    }

    private function storePhoto(UploadedFile $file): ?string
    {
        $path = $file->store(self::PHOTO_DIR.'/'.date('Y/m'), MediaDisk::slipDisk());

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** 2.0 → "2", 1.5 → "1.5" */
    private function number(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }
}
