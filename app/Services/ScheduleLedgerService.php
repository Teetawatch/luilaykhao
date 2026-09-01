<?php

namespace App\Services;

use App\Models\ScheduleExpense;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * สมุดบัญชีหน้างานของรอบเดินทาง — รายรับ/รายจ่ายที่เกิดจริงระหว่างทริป
 *
 * เก็บบนตารางเดียวกับค่าใช้จ่ายที่แอดมินคีย์ (`schedule_expenses`) เพื่อให้ยอด
 * ที่สตาฟจดหน้างานไหลเข้าหน้ากำไรของแอดมินทันที ไม่ต้องคีย์ซ้ำ ต่างกันแค่
 * แถวของสตาฟจะมี `kind`/`category`/`slip_path`/`spent_at` ครบ
 */
class ScheduleLedgerService
{
    /** สลิปเก็บบนดิสก์ private เหมือนสลิปโอนเงิน — path ต้องขึ้นต้นด้วย slips/ */
    private const SLIP_DIR = 'slips/expenses';

    public function __construct(private ExpenseAuditService $auditService) {}

    /**
     * รายการทั้งหมดของรอบ + ยอดสรุป
     *
     * @param  int|null  $viewerId  ผู้ใช้ที่เปิดดู — ใช้บอกว่าแถวไหนแก้/ลบเองได้
     */
    public function forSchedule(TripSchedule $schedule, ?int $viewerId = null): array
    {
        $entries = ScheduleExpense::with('creator:id,name')
            ->where('schedule_id', $schedule->id)
            // ใหม่สุดอยู่บน — หน้างานเพิ่งจดอะไรไปต้องเห็นก่อน
            ->orderByRaw('COALESCE(spent_at, created_at) DESC')
            ->orderByDesc('id')
            ->get();

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                // ปิดงบแล้ว = แอปต้องซ่อนปุ่มเพิ่ม/แก้ ไม่ใช่ปล่อยให้กดแล้วเด้ง error
                'finance_closed' => $schedule->financeClosed(),
            ],
            // ข้อบังคับที่แอปต้องรู้ล่วงหน้า — เกินยอดนี้ต้องถ่ายสลิป, ต้องเลือกหมวด
            'rules' => [
                'slip_required_above' => SiteSettings::financeSlipRequiredAbove(),
                'require_category' => SiteSettings::financeRequiresCategory(),
            ],
            'summary' => $this->totals($entries),
            'categories' => [
                'expense' => $this->categoryOptions(ScheduleExpense::EXPENSE_CATEGORIES),
                'income' => $this->categoryOptions(ScheduleExpense::INCOME_CATEGORIES),
            ],
            'items' => $entries->map(fn (ScheduleExpense $e) => $this->present($e, $viewerId))->values()->all(),
        ];
    }

    /**
     * ยอดรวมของชุดรายการ — รายรับ/รายจ่าย/คงเหลือ
     *
     * @param  Collection<int, ScheduleExpense>  $entries
     */
    public function totals(Collection $entries): array
    {
        $income = round((float) $entries->where('kind', ScheduleExpense::KIND_INCOME)->sum('amount'), 2);
        // แถวเก่าที่ยังไม่มี kind (ก่อน migration) นับเป็นรายจ่ายตามความหมายเดิม
        $expense = round((float) $entries->where('kind', '!=', ScheduleExpense::KIND_INCOME)->sum('amount'), 2);

        return [
            'income_total' => $income,
            'expense_total' => $expense,
            'net' => round($income - $expense, 2),
            'items_count' => $entries->count(),
        ];
    }

    public function record(TripSchedule $schedule, User $user, array $data, ?UploadedFile $slip = null, ?string $reason = null): ScheduleExpense
    {
        $kind = $data['kind'] ?? ScheduleExpense::KIND_EXPENSE;

        $entry = ScheduleExpense::create([
            'schedule_id' => $schedule->id,
            'kind' => $kind,
            'category' => $this->normalizeCategory($kind, $data['category'] ?? null),
            'name' => trim((string) $data['name']),
            'amount' => round((float) $data['amount'], 2),
            'note' => $data['note'] ?? null,
            'slip_path' => $slip ? $this->storeSlip($slip) : null,
            'spent_at' => $data['spent_at'] ?? now('Asia/Bangkok')->toDateString(),
            'created_by' => $user->id,
        ]);

        $this->auditService->created($entry, $user, $reason);

        return $entry;
    }

    public function update(ScheduleExpense $entry, array $data, ?UploadedFile $slip = null, ?User $actor = null, ?string $reason = null): ScheduleExpense
    {
        $before = $entry->auditSnapshot();
        $kind = $data['kind'] ?? $entry->kind;

        $payload = [
            'kind' => $kind,
            'category' => $this->normalizeCategory($kind, $data['category'] ?? $entry->category),
        ];

        foreach (['name', 'note', 'spent_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if (array_key_exists('amount', $data)) {
            $payload['amount'] = round((float) $data['amount'], 2);
        }

        if ($slip) {
            // ถ่ายสลิปใหม่ทับ — ลบไฟล์เดิมทิ้ง ไม่ปล่อยให้ค้างกินที่
            $this->deleteSlip($entry->slip_path);
            $payload['slip_path'] = $this->storeSlip($slip);
        } elseif (($data['remove_slip'] ?? false) && $entry->slip_path) {
            $this->deleteSlip($entry->slip_path);
            $payload['slip_path'] = null;
        }

        $entry->update($payload);

        $this->auditService->updated($entry, $before, $actor, $reason);

        return $entry->fresh('creator');
    }

    /**
     * ลบรายการ — soft delete เท่านั้น และ "ไม่" ลบสลิปทิ้ง
     *
     * สลิปคือหลักฐานว่าเงินก้อนนั้นเคยถูกบันทึกไว้จริง ลบไฟล์ทิ้งพร้อมแถวแปลว่า
     * การลบเงินออกจากงบตรวจสอบย้อนหลังไม่ได้เลย — ยอมให้ไฟล์ค้างบน bucket ดีกว่า
     */
    public function delete(ScheduleExpense $entry, ?User $actor = null, ?string $reason = null): void
    {
        $this->auditService->deleted($entry, $actor, $reason);

        $entry->forceFill(['deleted_by' => $actor?->id])->save();
        $entry->delete();
    }

    public function present(ScheduleExpense $entry, ?int $viewerId = null): array
    {
        return [
            'id' => $entry->id,
            'kind' => $entry->kind ?: ScheduleExpense::KIND_EXPENSE,
            'category' => $entry->category,
            'category_label' => $entry->categoryLabel(),
            'name' => $entry->name,
            'amount' => (float) $entry->amount,
            'note' => $entry->note,
            'slip_url' => MediaDisk::slipUrl($entry->slip_path),
            'spent_at' => $entry->spent_at?->toDateString(),
            'created_by' => $entry->created_by,
            'created_by_name' => $entry->relationLoaded('creator') ? $entry->creator?->name : null,
            'created_at' => $entry->created_at?->toIso8601String(),
            // แก้/ลบได้เฉพาะรายการที่ตัวเองบันทึก — ของคนอื่นให้แอดมินจัดการ
            'can_edit' => $viewerId !== null && (int) $entry->created_by === (int) $viewerId,
        ];
    }

    private function categoryOptions(array $map): array
    {
        return collect($map)
            ->map(fn ($label, $key) => ['value' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    private function normalizeCategory(string $kind, ?string $category): ?string
    {
        if (! $category) {
            return null;
        }

        $map = $kind === ScheduleExpense::KIND_INCOME
            ? ScheduleExpense::INCOME_CATEGORIES
            : ScheduleExpense::EXPENSE_CATEGORIES;

        return isset($map[$category]) ? $category : null;
    }

    private function storeSlip(UploadedFile $file): ?string
    {
        $path = $file->store(self::SLIP_DIR.'/'.date('Y/m'), MediaDisk::slipDisk());

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function deleteSlip(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'slips/')) {
            return;
        }

        try {
            Storage::disk(MediaDisk::slipDisk())->delete($path);
        } catch (\Throwable $e) {
            // ลบไฟล์ไม่สำเร็จไม่ควรทำให้การลบรายการล้มเหลว — แค่ปล่อยไฟล์ค้าง
        }
    }
}
