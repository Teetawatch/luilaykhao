<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\EmailLog;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หลักฐานการแจ้ง "คนยังไม่ครบ" 7 วันก่อนเดินทาง — ไว้ตอบลูกค้าที่บอกว่าไม่ได้รับแจ้ง
 *
 * หลักฐานมาสองทาง:
 * - email_logs: ส่งถึงที่อยู่ไหน Brevo รับไปเมื่อไหร่ (Message-ID) และเนื้อหาที่ส่งจริง
 * - smart_notifications: แจ้งในแอปที่ job ยิงคู่กันเสมอ และลูกค้าเปิดอ่านแล้วหรือยัง
 *
 * รอบที่แจ้งไปก่อนมีตาราง email_logs ยังมีแค่หลักฐานในแอป จึงรวมทั้งสองทางเข้าด้วยกัน
 * ต่อใบจอง แทนที่จะโชว์เฉพาะที่เพิ่งเริ่มเก็บ
 */
class AdminUnderfilledEmailController extends Controller
{
    use ApiResponse;

    private const NOTIFICATION_TYPE = 'trip_underfilled_warning';

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $to = $request->filled('to')
            ? CarbonImmutable::parse($request->input('to'), 'Asia/Bangkok')->endOfDay()
            : CarbonImmutable::now('Asia/Bangkok')->endOfDay();
        $from = $request->filled('from')
            ? CarbonImmutable::parse($request->input('from'), 'Asia/Bangkok')->startOfDay()
            : $to->subDays(60)->startOfDay();

        $range = [$from->utc(), $to->utc()];

        $logs = EmailLog::where('type', EmailLog::TYPE_UNDERFILLED_WARNING)
            ->whereBetween('created_at', $range)
            ->orderBy('id')
            ->get();

        $notifications = SmartNotification::where('type', self::NOTIFICATION_TYPE)
            ->whereBetween('created_at', $range)
            ->orderBy('id')
            ->get()
            ->filter(fn ($n) => ! empty($n->data['booking_ref']))
            ->keyBy(fn ($n) => $n->data['booking_ref']);

        $refs = $logs->pluck('booking_ref')->filter()
            ->merge($notifications->keys())
            ->unique()
            ->values();

        $bookings = Booking::with(['user:id,name,phone,email', 'passengers:id,booking_id,name,phone'])
            ->whereIn('booking_ref', $refs)
            ->get(['id', 'booking_ref', 'user_id', 'schedule_id', 'status'])
            ->keyBy('booking_ref');

        $logsByRef = $logs->groupBy(fn ($log) => $log->booking_ref ?? 'log-'.$log->id);

        $rows = $logsByRef->keys()->merge($notifications->keys())->unique()->map(
            function ($ref) use ($logsByRef, $notifications, $bookings) {
                $bookingLogs = $logsByRef->get($ref, collect());
                $notification = $notifications->get($ref);
                $booking = $bookings->get($ref);
                $first = $bookingLogs->first();
                $leadPassenger = $booking?->passengers->first();

                return [
                    'booking_ref' => $first?->booking_ref ?? $ref,
                    'booking_status' => $booking?->status,
                    'schedule_id' => $first?->schedule_id ?? $booking?->schedule_id,
                    'meta' => $first?->meta,
                    'customer_name' => $booking?->user?->name ?? $leadPassenger?->name,
                    'customer_phone' => $booking?->user?->phone ?? $leadPassenger?->phone,
                    'warned_at' => collect([$first?->created_at, $notification?->created_at])
                        ->filter()->min()?->toIso8601String(),
                    'emails' => $bookingLogs->map(fn (EmailLog $log) => [
                        'id' => $log->id,
                        'recipient' => $log->recipient,
                        'subject' => $log->subject,
                        'status' => $log->status,
                        'message_id' => $log->message_id,
                        'error_message' => $log->error_message,
                        'queued_at' => $log->created_at?->toIso8601String(),
                        'sent_at' => $log->sent_at?->toIso8601String(),
                        'failed_at' => $log->failed_at?->toIso8601String(),
                        'has_body' => $log->status === EmailLog::STATUS_SENT,
                    ])->values(),
                    // ใบจองที่แจ้งไปก่อนเริ่มเก็บ email_logs — อีเมลน่าจะออกแล้วแต่ไม่มีบันทึกยืนยัน
                    'email_unrecorded' => $bookingLogs->isEmpty(),
                    'in_app' => $notification ? [
                        'sent_at' => $notification->created_at?->toIso8601String(),
                        'read_at' => $notification->read_at?->toIso8601String(),
                        'is_read' => (bool) $notification->is_read,
                    ] : null,
                ];
            }
        );

        $schedules = TripSchedule::with('trip:id,title')
            ->whereIn('id', $rows->pluck('schedule_id')->filter()->unique())
            ->get(['id', 'trip_id', 'departure_date', 'booked_seats', 'status'])
            ->keyBy('id');

        $groups = $rows->groupBy(fn ($row) => $row['schedule_id'] ?? 0)->map(
            function ($items, $scheduleId) use ($schedules) {
                $schedule = $schedules->get($scheduleId);
                $meta = $items->pluck('meta')->filter()->first() ?? [];

                return [
                    'schedule_id' => $scheduleId ?: null,
                    'trip_title' => $schedule?->trip?->title ?? ($meta['trip_title'] ?? null),
                    'departure_date' => $schedule?->departure_date?->toDateString() ?? ($meta['departure_date'] ?? null),
                    'schedule_status' => $schedule?->status,
                    // ตัวเลขตอนที่แจ้ง ไม่ใช่ตอนนี้ — สิ่งที่ลูกค้าเห็นในอีเมลวันนั้น
                    'booked_seats_at_warning' => $meta['booked_seats'] ?? null,
                    'min_seats' => $meta['min_seats'] ?? null,
                    'booked_seats_now' => $schedule ? (int) $schedule->booked_seats : null,
                    'warned_at' => $items->pluck('warned_at')->filter()->min(),
                    'bookings' => $items->map(fn ($row) => collect($row)->except('meta'))->values(),
                ];
            }
        )->sortByDesc('warned_at')->values();

        $emails = $logs->countBy('status');

        return $this->success([
            'groups' => $groups,
            'summary' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'schedules' => $groups->count(),
                'bookings' => $rows->count(),
                'emails_sent' => (int) $emails->get(EmailLog::STATUS_SENT, 0),
                'emails_queued' => (int) $emails->get(EmailLog::STATUS_QUEUED, 0),
                'emails_failed' => (int) $emails->get(EmailLog::STATUS_FAILED, 0),
                'no_email' => (int) $emails->get(EmailLog::STATUS_SKIPPED, 0),
                'in_app_read' => $rows->filter(fn ($r) => $r['in_app']['is_read'] ?? false)->count(),
            ],
        ]);
    }

    /** เนื้อหาอีเมลฉบับที่ส่งออกไปจริง — เปิดดู/พิมพ์เป็นหลักฐานได้ */
    public function show(int $id): JsonResponse
    {
        $log = EmailLog::where('type', EmailLog::TYPE_UNDERFILLED_WARNING)->findOrFail($id);

        return $this->success([
            'id' => $log->id,
            'booking_ref' => $log->booking_ref,
            'recipient' => $log->recipient,
            'subject' => $log->subject,
            'status' => $log->status,
            'message_id' => $log->message_id,
            'sent_at' => $log->sent_at?->toIso8601String(),
            'html' => $log->html_body,
        ]);
    }
}
