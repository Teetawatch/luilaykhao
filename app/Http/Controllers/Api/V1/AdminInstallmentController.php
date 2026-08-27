<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\InstallmentPayment;
use App\Models\TripSchedule;
use App\Services\SlipOcrService;
use App\Support\MediaDisk;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ภาพรวมการผ่อนชำระทั้งระบบ — ใครจ่ายไปกี่งวด เหลืออีกเท่าไหร่ เลยกำหนดหรือยัง
 * และสลิปของแต่ละงวดพร้อมผลตรวจ OCR
 *
 * แยกจาก AdminPaymentController (ซึ่งมองมุม "ใครค้างจ่าย + ส่งลิงก์ทวง") เพราะหน้านี้
 * ต้องการประวัติครบทุกงวดรวมงวดที่จ่ายไปแล้ว พร้อมลิงก์สลิปที่เซ็นใหม่ทุกครั้งที่เปิดดู
 */
class AdminInstallmentController extends Controller
{
    use ApiResponse;

    /** งวดที่ครบกำหนดภายในกี่วันถือว่า "ใกล้ถึงกำหนด" */
    private const DUE_SOON_DAYS = 7;

    /**
     * รายการการจองแบบผ่อนชำระทั้งหมด พร้อมสรุปยอดรวมและตัวเลือกรอบเดินทาง
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'filter' => ['nullable', 'in:all,outstanding,overdue,due_soon,needs_review,completed'],
            'schedule_id' => ['nullable', 'integer'],
            'include_cancelled' => ['nullable', 'boolean'],
        ]);

        $filter = $validated['filter'] ?? 'all';
        $search = trim($validated['search'] ?? '');
        $includeCancelled = (bool) ($validated['include_cancelled'] ?? false);

        // ชุดข้อมูลฐาน (ไม่ผ่าน search/filter) — การ์ดสรุปด้านบนต้องนิ่ง ไม่เปลี่ยนตามคำค้น
        // ไม่งั้นตัวเลข "เลยกำหนด 3 ราย" จะกลายเป็น 0 ทันทีที่พิมพ์ชื่อคนอื่นลงช่องค้นหา
        $scoped = $this->baseQuery($includeCancelled)
            ->when(! empty($validated['schedule_id']), fn (Builder $q) => $q->where('schedule_id', $validated['schedule_id']))
            ->get()
            ->map(fn (Booking $booking) => $this->summarize($booking));

        $rows = $scoped
            ->filter(fn (array $row) => $this->matchesFilter($row, $filter))
            ->filter(fn (array $row) => $this->matchesSearch($row, $search))
            // ค้างชำระขึ้นก่อนเสมอ เรียงตามงวดที่ครบกำหนดเร็วสุด — คนที่เลยกำหนดจะลอยขึ้นบนสุดเอง
            ->sortBy(fn (array $row) => ($row['is_complete'] ? '1' : '0').($row['next_due']['due_date'] ?? '9999-12-31'))
            ->values();

        return $this->success([
            'summary' => $this->summaryOf($scoped),
            'schedules' => $this->scheduleOptions($includeCancelled),
            'items' => $rows->all(),
        ]);
    }

    /**
     * รายละเอียดการจองเดียว — ใช้รีเฟรชแถวหลังอนุมัติ/ปฏิเสธสลิป และเพื่อให้ได้
     * ลิงก์สลิปที่เพิ่งเซ็นใหม่ (ลิงก์เดิมหมดอายุใน 30 นาที)
     */
    public function show(string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)
            ->where('payment_type', 'installment')
            ->with(['user', 'schedule.trip', 'passengers', 'installmentPayments'])
            ->firstOrFail();

        return $this->success($this->summarize($booking, withPayUrl: true));
    }

    /**
     * @return Builder<Booking>
     */
    private function baseQuery(bool $includeCancelled): Builder
    {
        return Booking::query()
            ->where('payment_type', 'installment')
            ->when(
                ! $includeCancelled,
                fn (Builder $q) => $q->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
            )
            ->with(['user', 'schedule.trip', 'passengers', 'installmentPayments']);
    }

    /**
     * รอบเดินทางที่มีการจองแบบผ่อนชำระอยู่ — ใช้เป็นตัวเลือกในตัวกรอง
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduleOptions(bool $includeCancelled): array
    {
        return TripSchedule::query()
            ->whereHas('bookings', fn (Builder $q) => $q->where('payment_type', 'installment')
                ->when(
                    ! $includeCancelled,
                    fn (Builder $inner) => $inner->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES),
                ))
            ->with('trip:id,title')
            ->orderByDesc('departure_date')
            ->get()
            ->map(fn (TripSchedule $schedule) => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->format('Y-m-d'),
            ])
            ->all();
    }

    /**
     * สรุปการผ่อนชำระของการจองหนึ่งรายการ พร้อมรายละเอียดครบทุกงวด
     *
     * @return array<string, mixed>
     */
    private function summarize(Booking $booking, bool $withPayUrl = false): array
    {
        $today = $this->today();

        $installments = $booking->installmentPayments
            ->sortBy('installment_no')
            ->map(fn (InstallmentPayment $installment) => $this->installmentRow($installment, $booking, $today))
            ->values();

        $paid = $installments->where('is_paid', true);
        $unpaid = $installments->where('is_paid', false);
        $overdue = $unpaid->where('is_overdue', true);

        $next = $unpaid->first();
        $total = (float) $booking->total_amount;
        $paidAmount = round((float) $paid->sum('amount'), 2);
        // ยอดคงเหลือคิดจากงวดที่ยังไม่จ่ายจริง ไม่ใช่ total - paid_amount เพราะยอดรวมของงวด
        // อาจถูกแอดมินแก้รายงวดจนไม่เท่ากับ total_amount เป๊ะ
        $outstanding = round((float) $unpaid->sum('amount'), 2);
        $count = $installments->count();

        $passenger = $booking->passengers->first();

        return [
            'booking_id' => $booking->id,
            'booking_ref' => $booking->booking_ref,
            'booking_status' => $booking->status,
            'created_at' => $booking->created_at?->toISOString(),
            'customer_name' => $passenger->name ?? $booking->user?->name ?? '-',
            'customer_phone' => $passenger->phone ?? $booking->user?->phone,
            'customer_email' => $booking->user?->email ?? $passenger->email ?? null,
            'trip_title' => $booking->schedule?->trip?->title,
            'schedule_id' => $booking->schedule_id,
            'departure_date' => $booking->schedule?->departure_date?->format('Y-m-d'),

            'installment_count' => $count,
            'planned_count' => (int) ($booking->installment_count ?: $count),
            'paid_count' => $paid->count(),
            'remaining_count' => $unpaid->count(),
            'progress_percent' => $count > 0 ? (int) round($paid->count() / $count * 100) : 0,

            'total_amount' => $total,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => $outstanding,
            'overdue_count' => $overdue->count(),
            'overdue_amount' => round((float) $overdue->sum('amount'), 2),
            'needs_review_count' => $installments->where('needs_review', true)->count(),

            'is_complete' => $count > 0 && $unpaid->isEmpty(),
            'next_due' => $next ? [
                'installment_no' => $next['installment_no'],
                'amount' => $next['amount'],
                'due_date' => $next['due_date'],
                'days_until_due' => $next['days_until_due'],
                'is_overdue' => $next['is_overdue'],
            ] : null,

            // payUrl() สร้าง token ลงฐานข้อมูลถ้ายังไม่มี — ทำทีละใบตอนกางดูรายละเอียด
            // ไม่ใช่เขียนทุกแถวทุกครั้งที่เปิดหน้ารายการ
            'pay_url' => $withPayUrl && $unpaid->isNotEmpty() ? $booking->payUrl() : null,
            'installments' => $installments->all(),
        ];
    }

    /**
     * งวดหนึ่งงวด — สถานะที่คำนวณสดจากวันที่ (ไม่พึ่งคอลัมน์ status ที่อาจค้างเป็น
     * pending ทั้งที่เลยกำหนดแล้ว) พร้อมสลิปและผลตรวจ
     *
     * @return array<string, mixed>
     */
    private function installmentRow(InstallmentPayment $installment, Booking $booking, Carbon $today): array
    {
        $isPaid = $installment->status === 'paid';
        // อ่านเป็นวันตามปฏิทินไทยก่อนเทียบ — due_date เป็นคอลัมน์ date ที่ Laravel คืนมาเป็น
        // เที่ยงคืน UTC ถ้าเทียบตรง ๆ กับวันนี้ (+07:00) จะเพี้ยนไป 7 ชั่วโมงเสมอ
        $dueDate = $installment->due_date
            ? Carbon::createFromFormat('Y-m-d', $installment->due_date->format('Y-m-d'), 'Asia/Bangkok')->startOfDay()
            : null;
        $daysUntilDue = $dueDate ? (int) $today->diffInDays($dueDate, false) : null;
        $isOverdue = ! $isPaid && $daysUntilDue !== null && $daysUntilDue < 0;

        // งวดที่ 1 ใช้สลิปใบเดียวกับ bookings.slip_path (ดู PaymentController::charge)
        // การจองเก่าบางรายการเก็บไว้ที่ booking อย่างเดียว จึงต้อง fallback ไม่งั้นสลิปงวดแรกหาย
        $slipPath = $installment->slip_path
            ?: ((int) $installment->installment_no === 1 ? $booking->slip_path : null);

        $ocrStatus = $installment->slip_ocr_status
            ?: (($slipPath && (int) $installment->installment_no === 1) ? $booking->slip_ocr_status : null);

        $ocrResult = $installment->slip_ocr_result
            ?: (($slipPath && (int) $installment->installment_no === 1) ? $booking->slip_ocr_result : null);

        return [
            'id' => $installment->id,
            'installment_no' => (int) $installment->installment_no,
            'label' => "งวดที่ {$installment->installment_no}",
            'amount' => (float) $installment->amount,
            'due_date' => $dueDate?->toDateString(),
            'days_until_due' => $daysUntilDue,
            'is_paid' => $isPaid,
            'is_overdue' => $isOverdue,
            'is_due_soon' => ! $isPaid && ! $isOverdue && $daysUntilDue !== null && $daysUntilDue <= self::DUE_SOON_DAYS,
            'display_status' => match (true) {
                $isPaid => 'paid',
                $isOverdue => 'overdue',
                $daysUntilDue !== null && $daysUntilDue <= self::DUE_SOON_DAYS => 'due_soon',
                default => 'pending',
            },
            'paid_at' => $installment->paid_at?->toISOString(),
            'transfer_datetime' => $installment->transfer_datetime?->toISOString(),
            'payment_method' => $installment->payment_method,
            'payment_ref' => $installment->payment_ref,

            'has_slip' => (bool) $slipPath,
            'slip_url' => MediaDisk::slipUrl($slipPath),
            'slip_is_pdf' => $slipPath ? str_ends_with(strtolower($slipPath), '.pdf') : false,
            'slip_ocr_status' => $ocrStatus,
            'slip_ocr' => $this->ocrDetail($ocrResult, (float) $installment->amount),
            // สลิปที่ OCR ยังไม่ผ่าน/ยังไม่ตรวจ = งานที่รอแอดมินตัดสินใจด้วยตา
            'needs_review' => (bool) $slipPath && in_array($ocrStatus, [
                SlipOcrService::STATUS_PENDING,
                SlipOcrService::STATUS_FAILED,
                null,
            ], true),
        ];
    }

    /**
     * ผลอ่านสลิปที่ OCR ดึงได้ + ส่วนต่างจากยอดที่ต้องจ่าย
     *
     * รับเป็น mixed เพราะคอลัมน์ json ที่ cast เป็น array ไม่ได้คืน array เสมอ: แถวที่ถูก
     * encode ซ้อนสองชั้น (เก็บสตริง JSON ลงในคอลัมน์ที่ encode ให้อีกที) จะ decode
     * ออกมาเป็นสตริง แกะอีกชั้นให้แทนที่จะทิ้ง — ข้อมูลยังอ่านได้อยู่ และทั้งหน้า
     * ไม่ควรล่มเพราะสลิปใบเดียวที่รูปร่างเพี้ยน
     *
     * @return array<string, mixed>|null
     */
    private function ocrDetail(mixed $raw, float $expectedAmount): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $amount = isset($raw['amount']) ? (float) $raw['amount'] : null;

        return [
            'status' => $raw['status'] ?? null,
            'amount' => $amount,
            'amount_diff' => $amount !== null ? round($amount - $expectedAmount, 2) : null,
            'datetime' => $raw['datetime'] ?? null,
            'bank' => $raw['bank'] ?? null,
            'transaction_id' => $raw['transaction_id'] ?? null,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summaryOf(Collection $rows): array
    {
        $active = $rows->where('is_complete', false);

        return [
            'bookings' => $rows->count(),
            'active_bookings' => $active->count(),
            'completed_bookings' => $rows->where('is_complete', true)->count(),
            'overdue_bookings' => $rows->where('overdue_count', '>', 0)->count(),
            'due_soon_bookings' => $rows->filter(
                fn (array $row) => ! $row['is_complete']
                    && ! ($row['next_due']['is_overdue'] ?? false)
                    && ($row['next_due']['days_until_due'] ?? null) !== null
                    && $row['next_due']['days_until_due'] <= self::DUE_SOON_DAYS,
            )->count(),
            'needs_review_bookings' => $rows->where('needs_review_count', '>', 0)->count(),
            'collected_amount' => round((float) $rows->sum('paid_amount'), 2),
            'outstanding_amount' => round((float) $rows->sum('outstanding_amount'), 2),
            'overdue_amount' => round((float) $rows->sum('overdue_amount'), 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesFilter(array $row, string $filter): bool
    {
        return match ($filter) {
            'outstanding' => ! $row['is_complete'],
            'overdue' => $row['overdue_count'] > 0,
            'due_soon' => ! $row['is_complete']
                && ! ($row['next_due']['is_overdue'] ?? false)
                && ($row['next_due']['days_until_due'] ?? null) !== null
                && $row['next_due']['days_until_due'] <= self::DUE_SOON_DAYS,
            'needs_review' => $row['needs_review_count'] > 0,
            'completed' => $row['is_complete'],
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function matchesSearch(array $row, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        $needle = mb_strtolower($search);

        foreach (['booking_ref', 'customer_name', 'customer_phone', 'customer_email', 'trip_title'] as $key) {
            if ($row[$key] && str_contains(mb_strtolower((string) $row[$key]), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * วันนี้ตามเวลาไทย — วันครบกำหนดเก็บเป็นวันที่ตามปฏิทินไทย ถ้าเทียบกับ now() (UTC)
     * งวดที่ครบกำหนดวันนี้จะกลายเป็น "เลยกำหนด" ในช่วงเช้ามืดของไทย
     */
    private function today(): Carbon
    {
        return Carbon::now('Asia/Bangkok')->startOfDay();
    }
}
