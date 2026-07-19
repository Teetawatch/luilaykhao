<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\InstallmentPayment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * รวบรวมการจองที่ยังมียอดค้างชำระ (ค่างวด หรือยอดส่วนที่เหลือของมัดจำ)
 * และส่งลิงก์ชำระเงินให้ลูกค้าเป็นรายคน ใช้ร่วมกันทั้ง Admin API และหน้าเว็บ admin
 */
class OutstandingPaymentService
{
    public function __construct(
        private MailService $mailService,
        private SmsService $smsService,
        private InstallmentPaymentService $installmentPaymentService,
        private BalancePaymentService $balancePaymentService,
    ) {}

    /**
     * รายการการจองที่ยังค้างชำระ (เรียงตามวันครบกำหนด) แต่ละแถวพร้อม pay_url
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $scheduleId = null, ?string $search = null): Collection
    {
        $query = Booking::query()
            ->where('status', 'confirmed')
            ->whereIn('payment_type', ['installment', 'deposit'])
            ->with(['schedule.trip', 'user', 'passengers', 'installmentPayments'])
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->where('payment_type', 'installment')
                        ->whereHas('installmentPayments', fn ($i) => $i->where('status', '!=', 'paid'));
                })->orWhere(function ($q2) {
                    $q2->where('payment_type', 'deposit')
                        ->whereNull('balance_paid_at')
                        ->where('balance_amount', '>', 0);
                });
            });

        if ($scheduleId) {
            $query->where('schedule_id', $scheduleId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereLike('booking_ref', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$search}%"))
                    ->orWhereHas('passengers', fn ($p) => $p->whereLike('name', "%{$search}%")
                        ->orWhereLike('phone', "%{$search}%"));
            });
        }

        return $query->get()
            ->map(fn (Booking $b) => $this->summarize($b))
            ->filter()
            ->sortBy(fn ($row) => $row['due_date'] ?? '9999-12-31')
            ->values();
    }

    /**
     * สรุปยอดค้างชำระของการจองหนึ่งรายการ — null ถ้าไม่มียอดค้าง
     *
     * @return array<string, mixed>|null
     */
    public function summarize(Booking $booking): ?array
    {
        $type = $booking->payment_type;

        if ($type === 'installment') {
            $all = $booking->installmentPayments->sortBy('installment_no')->values();
            $next = $all->firstWhere('status', '!=', 'paid');

            if (! $next) {
                return null;
            }

            $amount = (float) $next->amount;
            $dueDate = $next->due_date?->toDateString();
            $installmentNo = $next->installment_no;
            $label = "งวดที่ {$next->installment_no}/{$booking->installment_count}";
            // สลิปถูกแนบแล้วแต่ยังไม่ผ่านการตรวจ (VerifySlipJob ทำงานแบบ async)
            // สตาฟหน้างานต้องเห็นสถานะนี้ ไม่งั้นจะทวงซ้ำคนที่เพิ่งจ่ายไป
            $slipPending = filled($next->slip_path);

            // รายละเอียดครบทุกงวด — สตาฟหน้างานต้องตอบลูกค้าได้ว่า "จ่ายมาแล้ว
            // กี่งวด เหลืออีกเท่าไหร่" ไม่ใช่เห็นแค่งวดที่กำลังจะถึง
            $schedule = $all->map(fn ($i) => $this->installmentRow($i))->all();
            $paidTotal = (float) $all->where('status', 'paid')->sum('amount');
            $remainingTotal = (float) $all->where('status', '!=', 'paid')->sum('amount');
        } elseif ($type === 'deposit') {
            if (! $this->balancePaymentService->hasOutstandingBalance($booking)) {
                return null;
            }

            $amount = (float) $booking->balance_amount;
            $dueDate = $booking->balance_due_at?->toDateString();
            $installmentNo = null;
            $label = 'ยอดส่วนที่เหลือ';
            $slipPending = filled($booking->balance_slip_path);

            // มัดจำมองเป็น 2 งวดเสมอ (มัดจำที่จ่ายแล้ว + ยอดคงเหลือ) เพื่อให้
            // แอปเรนเดอร์ไทม์ไลน์เดียวกันได้ทั้งสองแบบการชำระ
            $schedule = [
                [
                    'installment_no' => 1,
                    'label' => 'มัดจำ',
                    'amount' => (float) $booking->deposit_amount,
                    'due_date' => $booking->created_at?->toDateString(),
                    'status' => 'paid',
                    'paid_at' => $booking->created_at?->toISOString(),
                    'slip_pending' => false,
                    'overdue' => false,
                ],
                [
                    'installment_no' => 2,
                    'label' => 'ยอดส่วนที่เหลือ',
                    'amount' => $amount,
                    'due_date' => $dueDate,
                    'status' => 'pending',
                    'paid_at' => null,
                    'slip_pending' => $slipPending,
                    'overdue' => $dueDate ? Carbon::parse($dueDate)->isPast() : false,
                ],
            ];
            $paidTotal = (float) $booking->deposit_amount;
            $remainingTotal = $amount;
        } else {
            return null;
        }

        $passenger = $booking->passengers->first();

        return [
            'booking_ref' => $booking->booking_ref,
            'customer_name' => $passenger->name ?? $booking->user?->name ?? '-',
            'phone' => $passenger->phone ?? null,
            'email' => $passenger->email ?? $booking->user?->email ?? null,
            'trip_title' => $booking->schedule->trip->title ?? '-',
            'departure_date' => $booking->schedule?->departure_date?->toDateString(),
            'departs_at' => $booking->schedule?->departs_at?->format('Y-m-d H:i:s'),
            'type' => $type,
            'label' => $label,
            'installment_no' => $installmentNo,
            'amount_due' => $amount,
            'due_date' => $dueDate,
            'overdue' => $dueDate ? Carbon::parse($dueDate)->isPast() : false,
            'slip_pending' => $slipPending,
            'total_amount' => (float) $booking->total_amount,
            'paid_total' => $paidTotal,
            'remaining_total' => $remainingTotal,
            'installment_count' => count($schedule),
            'paid_count' => count(array_filter($schedule, fn ($r) => $r['status'] === 'paid')),
            'schedule' => $schedule,
            'pay_url' => $booking->payUrl(),
        ];
    }

    /**
     * แปลงงวดหนึ่งเป็นแถวสำหรับแสดงผล
     *
     * @return array<string, mixed>
     */
    private function installmentRow(InstallmentPayment $installment): array
    {
        $paid = $installment->status === 'paid';
        $dueDate = $installment->due_date?->toDateString();

        return [
            'installment_no' => (int) $installment->installment_no,
            'label' => "งวดที่ {$installment->installment_no}",
            'amount' => (float) $installment->amount,
            'due_date' => $dueDate,
            'status' => $installment->status,
            'paid_at' => $installment->paid_at?->toISOString(),
            'slip_pending' => ! $paid && filled($installment->slip_path),
            'overdue' => ! $paid && $dueDate && Carbon::parse($dueDate)->isPast(),
        ];
    }

    /**
     * ส่งลิงก์ชำระเงินให้ลูกค้าผ่านช่องทางที่เลือก (email / sms)
     *
     * @param  array<int, string>  $channels
     * @return array<string, mixed>
     */
    public function sendLink(Booking $booking, array $channels = ['email']): array
    {
        $booking->loadMissing(['schedule.trip', 'user', 'passengers', 'installmentPayments']);
        $booking->ensurePaymentToken();

        if ($booking->payment_type === 'installment') {
            $next = $this->installmentPaymentService->nextDueInstallment($booking);
            if (! $next) {
                throw new \RuntimeException('การจองนี้ชำระครบทุกงวดแล้ว');
            }

            $reminderType = $this->reminderType($next->due_date);
            if (in_array('email', $channels, true)) {
                $this->mailService->sendInstallmentDueReminderEmail($booking, $next, $reminderType);
            }
            if (in_array('sms', $channels, true)) {
                $this->smsService->sendInstallmentReminder($next, $reminderType);
            }
        } elseif ($booking->payment_type === 'deposit') {
            if (! $this->balancePaymentService->hasOutstandingBalance($booking)) {
                throw new \RuntimeException('การจองนี้ไม่มียอดค้างชำระ');
            }

            if (in_array('email', $channels, true)) {
                $this->mailService->sendBalanceDueReminderEmail($booking);
            }
            if (in_array('sms', $channels, true)) {
                $this->smsService->sendBalanceDueReminder($booking);
            }
        } else {
            throw new \RuntimeException('การจองนี้ไม่รองรับลิงก์ชำระเงิน');
        }

        return $this->summarize($booking->fresh()->load(['schedule.trip', 'user', 'passengers', 'installmentPayments'])) ?? [];
    }

    private function reminderType(mixed $dueDate): string
    {
        if (! $dueDate) {
            return 'due_soon';
        }

        $due = Carbon::parse($dueDate)->startOfDay();
        $today = now()->startOfDay();

        return match (true) {
            $due->lt($today) => 'overdue',
            $due->eq($today) => 'due_today',
            default => 'due_soon',
        };
    }
}
