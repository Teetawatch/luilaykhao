<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\AtRiskScheduleService;
use App\Services\SmsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "รอบเสี่ยงไม่ออก" — หน้ารวมรอบที่ใกล้เดินทางแต่คนยังไม่ครบขั้นต่ำ
 * พร้อมปุ่มลงมือแก้ในที่เดียว (ชวนช่วยกันเปิดรอบ / ลดราคารอบ / Flexi-Price / ย้ายคนรวมรอบ)
 */
class AdminAtRiskScheduleController extends Controller
{
    use ApiResponse;

    /** 5 ท่อน SMS ภาษาไทย (67 ตัวอักษรต่อท่อน) — ยาวกว่านี้เปลืองเครดิตเกินเหตุ */
    private const UNDERFILLED_SMS_MAX = 335;

    public function __construct(
        private AtRiskScheduleService $radar,
        private SmsService $sms,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $window = $request->integer('days') ?: AtRiskScheduleService::WINDOW_DAYS;
        $window = max(1, min(90, $window));

        $rows = $this->radar->atRisk($window);

        return $this->success([
            'schedules' => $rows->values(),
            'summary' => [
                'count' => $rows->count(),
                'with_bookings' => $rows->where('booked_seats', '>', 0)->count(),
                'critical' => $rows->where('severity', 'critical')->count(),
                'revenue_at_risk' => round((float) $rows->sum('revenue_at_risk'), 2),
                'min_seats' => $this->radar->minSeats(),
                'window_days' => $window,
            ],
        ]);
    }

    /** ยิงแจ้งเตือนหาผู้ที่จองรอบนี้แล้ว ให้ช่วยกันชวนเพื่อนมาเติมที่นั่ง */
    public function nudge(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($id);

        try {
            $result = $this->radar->sendRallyNudge($schedule, $request->boolean('force'));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(
            ['notified' => $result['notified']],
            "ส่งคำชวนถึงผู้ร่วมทริปแล้ว {$result['notified']} ท่าน",
        );
    }

    /** ตัวอย่าง SMS แจ้งคนไม่ครบ + รายชื่อผู้รับ ก่อนทีมงานกดส่งจริง */
    public function underfilledSmsPreview(int $id): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($id);
        $recipients = $this->radar->underfilledSmsRecipients($schedule);

        return $this->success([
            'message' => $this->radar->underfilledSmsTemplate($schedule),
            'booked_seats' => (int) $schedule->booked_seats,
            'min_seats' => $this->radar->minSeats(),
            'sms_enabled' => $this->sms->isConfigured(),
            'recipients' => $recipients,
            'counts' => [
                'ready' => $recipients->where('status', 'ready')->count(),
                'no_phone' => $recipients->where('status', 'no_phone')->count(),
                'already' => $recipients->whereNotIn('status', ['ready', 'no_phone'])->count(),
            ],
        ]);
    }

    /** ส่ง SMS "รอบนี้คนไม่ครบ ขอเงินคืนเต็มจำนวนได้ทางไลน์" ถึงผู้ที่จ่ายเงินแล้ว */
    public function sendUnderfilledSms(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.self::UNDERFILLED_SMS_MAX],
        ]);

        $schedule = TripSchedule::with('trip')->findOrFail($id);

        try {
            $result = $this->radar->sendUnderfilledSms($schedule, $data['message']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        $delivered = $result['sent'] + $result['queued'];
        $message = "ส่ง SMS แล้ว {$delivered} ราย";
        if ($result['failed'] > 0) {
            $message .= " · ส่งไม่สำเร็จ {$result['failed']} ราย";
        }
        if ($result['no_phone'] > 0) {
            $message .= " · ไม่มีเบอร์โทร {$result['no_phone']} ราย";
        }

        return $this->success($result, $message);
    }
}
