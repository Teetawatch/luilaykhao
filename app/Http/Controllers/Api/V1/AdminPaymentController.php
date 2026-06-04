<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\OutstandingPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin/Operator API สำหรับติดตามและส่งลิงก์ชำระเงินให้ลูกค้าที่ยังค้างจ่าย
 */
class AdminPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OutstandingPaymentService $outstandingPaymentService,
    ) {}

    /**
     * รายการการจองที่ยังค้างชำระ (ค่างวด + ยอดส่วนที่เหลือ) พร้อมลิงก์ชำระเงิน
     */
    public function outstanding(Request $request): JsonResponse
    {
        $rows = $this->outstandingPaymentService->rows(
            $request->integer('schedule_id') ?: null,
            $request->string('search')->trim()->value() ?: null,
        );

        return $this->success([
            'count' => $rows->count(),
            'total_due' => round((float) $rows->sum('amount_due'), 2),
            'items' => $rows->all(),
        ]);
    }

    /**
     * ส่งลิงก์ชำระเงินให้การจองหนึ่งรายการ
     */
    public function sendLink(Request $request, string $ref): JsonResponse
    {
        $channels = $this->validatedChannels($request);

        $booking = Booking::where('booking_ref', $ref)->firstOrFail();

        try {
            $row = $this->outstandingPaymentService->sendLink($booking, $channels);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($row, 'ส่งลิงก์ชำระเงินแล้ว');
    }

    /**
     * ส่งลิงก์ชำระเงินให้หลายรายการพร้อมกัน (ตามรอบเดินทาง หรือรายการที่เลือก)
     */
    public function sendLinksBulk(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'schedule_id' => ['nullable', 'integer'],
            'booking_refs' => ['nullable', 'array'],
            'booking_refs.*' => ['string'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);

        $channels = $this->validatedChannels($request);

        $rows = $this->outstandingPaymentService->rows($validated['schedule_id'] ?? null);

        if (! empty($validated['booking_refs'])) {
            $refs = collect($validated['booking_refs']);
            $rows = $rows->filter(fn ($row) => $refs->contains($row['booking_ref']))->values();
        }

        $sent = [];
        $failed = [];

        foreach ($rows as $row) {
            $booking = Booking::where('booking_ref', $row['booking_ref'])->first();
            if (! $booking) {
                continue;
            }

            try {
                $this->outstandingPaymentService->sendLink($booking, $channels);
                $sent[] = $row['booking_ref'];
            } catch (\Throwable $e) {
                $failed[] = ['booking_ref' => $row['booking_ref'], 'reason' => $e->getMessage()];
            }
        }

        return $this->success([
            'sent_count' => count($sent),
            'failed_count' => count($failed),
            'sent' => $sent,
            'failed' => $failed,
        ], 'ส่งลิงก์ชำระเงินเสร็จสิ้น');
    }

    /**
     * @return array<int, string>
     */
    private function validatedChannels(Request $request): array
    {
        $request->validate([
            'channels' => ['nullable', 'array'],
            'channels.*' => ['in:email,sms'],
        ]);

        $channels = $request->input('channels', ['email']);

        return empty($channels) ? ['email'] : array_values(array_unique($channels));
    }
}
