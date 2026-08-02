<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastNotificationJob;
use App\Models\Booking;
use App\Models\BroadcastDispatch;
use App\Models\FcmToken;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BroadcastNotificationService;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ส่งข้อความ/แจ้งเตือนถึงลูกค้าด้วยตัวเอง + ดูประวัติที่เคยส่ง
 *
 * ก่อนหน้านี้ push ทุกตัวยิงจาก job อัตโนมัติเท่านั้น (ทริปใหม่ ที่นั่งใกล้เต็ม
 * ฯลฯ) ทีมงานจึงส่งเรื่องเฉพาะกิจอย่าง "พรุ่งนี้ฝนตก เตรียมเสื้อกันฝน" ไม่ได้เลย
 * หน้านี้เติมช่องทางนั้น โดยใช้ท่อส่งเดิม ([SendBroadcastNotificationJob]) เพื่อให้
 * ข้อความไปโผล่ทั้ง push และกล่องแจ้งเตือนในแอปเหมือนกันทุกประการ
 */
class AdminBroadcastController extends Controller
{
    use ApiResponse;

    /** ประเภทที่ใช้กำกับข้อความที่แอดมินเขียนเอง (แยกจาก event อัตโนมัติ) */
    private const MANUAL_TYPE = 'admin_broadcast';

    /**
     * ประวัติการส่งทั้งหมด — ทั้งที่ระบบยิงเองและที่ทีมงานเขียนเอง พร้อมอัตราการเปิดอ่าน
     */
    public function index(Request $request): JsonResponse
    {
        $dispatches = BroadcastDispatch::with('sender')
            ->when($request->query('source') === 'manual', fn ($q) => $q->where('event_type', self::MANUAL_TYPE))
            ->when($request->query('source') === 'auto', fn ($q) => $q->where('event_type', '!=', self::MANUAL_TYPE))
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        // อ่านแล้วกี่คน — นับครั้งเดียวสำหรับทุกแถว กัน N+1
        $readCounts = SmartNotification::whereIn('broadcast_dispatch_id', $dispatches->pluck('id'))
            ->where('is_read', true)
            ->selectRaw('broadcast_dispatch_id, COUNT(*) as total')
            ->groupBy('broadcast_dispatch_id')
            ->pluck('total', 'broadcast_dispatch_id');

        return $this->success([
            'dispatches' => $dispatches->map(function (BroadcastDispatch $d) use ($readCounts) {
                $recipients = (int) ($d->recipients_count ?? 0);
                $read = (int) ($readCounts[$d->id] ?? 0);

                return [
                    'id' => $d->id,
                    'event_type' => $d->event_type,
                    'event_label' => $this->eventLabel($d->event_type),
                    'is_manual' => $d->event_type === self::MANUAL_TYPE,
                    'title' => $d->title,
                    'body' => $d->body,
                    'audience' => $d->audience,
                    'audience_label' => $d->audience_label,
                    'recipients_count' => $d->recipients_count,
                    'read_count' => $read,
                    'read_percent' => $recipients > 0 ? round($read * 100 / $recipients) : null,
                    'sent_by_name' => $d->sender?->name,
                    'created_at' => $d->created_at?->toISOString(),
                ];
            })->values(),
        ]);
    }

    /**
     * ตัวเลือกผู้รับ + จำนวนคนที่ยิงถึงได้จริง (มีอุปกรณ์ที่เปิดรับ push อยู่)
     * เรียกก่อนกดส่ง เพื่อให้ทีมงานเห็นว่ากำลังจะส่งหาใครกี่คน
     */
    public function audiences(): JsonResponse
    {
        $today = now(BroadcastNotificationService::TIMEZONE)->toDateString();

        $schedules = TripSchedule::with('trip')
            ->whereDate('departure_date', '>=', now(BroadcastNotificationService::TIMEZONE)->subDays(3)->toDateString())
            ->where('status', '!=', 'cancelled')
            ->orderBy('departure_date')
            ->limit(60)
            ->get();

        // จำนวนผู้รับที่ยิงถึงได้ของทุกรอบในคิวรีเดียว — กัน N+1 ตอนมีรอบเยอะ
        $reachablePerSchedule = Booking::whereIn('schedule_id', $schedules->pluck('id'))
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->whereIn('user_id', FcmToken::where('is_active', true)->select('user_id'))
            ->selectRaw('schedule_id, COUNT(DISTINCT user_id) as total')
            ->groupBy('schedule_id')
            ->pluck('total', 'schedule_id');

        $schedules = $schedules->map(fn (TripSchedule $s) => [
            'id' => $s->id,
            'label' => ($s->trip?->title ?? 'ทริป').' — '.ThaiDate::full($s->departure_date),
            'departure_date' => $s->departure_date?->toDateString(),
            'is_today' => $s->departure_date?->toDateString() === $today,
            'reachable' => (int) ($reachablePerSchedule[$s->id] ?? 0),
        ]);

        $trips = Trip::where('status', 'active')
            ->orderBy('title')
            ->limit(200)
            ->get(['id', 'title'])
            ->map(fn (Trip $t) => ['id' => $t->id, 'label' => $t->title]);

        return $this->success([
            'all_reachable' => $this->reachableCount(BroadcastDispatch::AUDIENCE_ALL),
            'schedules' => $schedules->values(),
            'trips' => $trips->values(),
            'quiet_hours' => [
                'enabled' => (bool) config('services.broadcast_notifications.quiet_hours', true),
                'start_hour' => BroadcastNotificationService::quietStartHour(),
                'end_hour' => BroadcastNotificationService::quietEndHour(),
            ],
        ]);
    }

    /**
     * ส่งข้อความ — ไม่หน่วงตามช่วงเวลางดรบกวน เพราะแอดมินเป็นคนกดเอง
     * (ระบบเตือนบนหน้าจอแล้วว่าตอนนี้อยู่ในช่วงดึก) และเรื่องเร่งด่วนอย่างสภาพอากาศ
     * รอถึงเช้าไม่ได้
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
            'audience' => ['required', 'in:all,trip,schedule'],
            'audience_id' => ['nullable', 'integer'],
        ]);

        $audience = $data['audience'];
        $audienceId = $data['audience_id'] ?? null;

        if ($audience !== BroadcastDispatch::AUDIENCE_ALL && ! $audienceId) {
            return $this->error('กรุณาเลือกทริปหรือรอบเดินทางที่ต้องการส่งถึง', 422);
        }

        [$label, $payload] = $this->resolveTarget($audience, $audienceId);
        if ($label === null) {
            return $this->error('ไม่พบทริปหรือรอบเดินทางที่เลือก', 404);
        }

        $reachable = $this->reachableCount($audience, $audienceId);
        if ($reachable === 0) {
            return $this->error('ยังไม่มีผู้รับที่เปิดรับการแจ้งเตือนในกลุ่มนี้', 422);
        }

        $dispatch = BroadcastDispatch::create([
            'event_type' => self::MANUAL_TYPE,
            // ข้อความที่เขียนเองไม่ต้อง dedupe แต่คอลัมน์ unique จึงใส่ค่าสุ่มกันชน
            'dedupe_key' => 'manual:'.Str::uuid(),
            'title' => $data['title'],
            'body' => $data['body'],
            'data' => $payload,
            'audience' => $audience,
            'audience_id' => $audienceId,
            'audience_label' => $label,
            'sent_by' => $request->user()->id,
        ]);

        dispatch(new SendBroadcastNotificationJob(
            self::MANUAL_TYPE,
            $data['title'],
            $data['body'],
            $payload,
            $dispatch->id,
            $audience,
            $audienceId,
        ));

        return $this->success([
            'id' => $dispatch->id,
            'audience_label' => $label,
            'reachable' => $reachable,
        ], "ส่งข้อความถึง {$label} แล้ว ({$reachable} คน)");
    }

    /**
     * ป้ายกำกับกลุ่มผู้รับ + deep link ที่แนบไปกับ push
     *
     * @return array{0: string|null, 1: array<string, mixed>}
     */
    private function resolveTarget(string $audience, ?int $audienceId): array
    {
        if ($audience === BroadcastDispatch::AUDIENCE_SCHEDULE) {
            $schedule = TripSchedule::with('trip')->find($audienceId);
            if (! $schedule) {
                return [null, []];
            }

            return [
                'รอบ '.($schedule->trip?->title ?? '').' '.ThaiDate::full($schedule->departure_date),
                [
                    'route' => 'trip',
                    'trip_slug' => $schedule->trip?->slug,
                    'trip_id' => $schedule->trip_id,
                    'schedule_id' => $schedule->id,
                ],
            ];
        }

        if ($audience === BroadcastDispatch::AUDIENCE_TRIP) {
            $trip = Trip::find($audienceId);
            if (! $trip) {
                return [null, []];
            }

            return [
                'ลูกค้าทริป '.$trip->title,
                ['route' => 'trip', 'trip_slug' => $trip->slug, 'trip_id' => $trip->id],
            ];
        }

        return ['ลูกค้าทั้งหมด', ['route' => 'home']];
    }

    /**
     * จำนวนคนที่ยิงถึงได้จริง — ต้องมีอุปกรณ์ที่ยัง active อยู่ และถ้าเป็นการส่ง
     * ถึงทุกคนต้องไม่ได้ปิดรับข่าวสารการตลาดไว้ (เกณฑ์เดียวกับตอนส่งจริง)
     */
    private function reachableCount(string $audience, ?int $audienceId = null): int
    {
        $query = User::query()->whereIn('id', FcmToken::where('is_active', true)->select('user_id'));

        if ($audience === BroadcastDispatch::AUDIENCE_ALL) {
            return $query->where('marketing_push_enabled', true)->count();
        }

        $scheduleIds = $audience === BroadcastDispatch::AUDIENCE_SCHEDULE
            ? [$audienceId]
            : TripSchedule::where('trip_id', $audienceId)->pluck('id')->all();

        $scheduleIds = array_filter($scheduleIds);
        if (empty($scheduleIds)) {
            return 0;
        }

        return $query->whereIn('id', Booking::whereIn('schedule_id', $scheduleIds)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all()
        )->count();
    }

    private function eventLabel(string $type): string
    {
        return match ($type) {
            self::MANUAL_TYPE => 'ทีมงานส่งเอง',
            'new_trip' => 'ทริปใหม่',
            'new_schedule' => 'เปิดรอบใหม่',
            'flash_sale' => 'Flash Sale',
            'low_seats' => 'ที่นั่งใกล้เต็ม',
            'sold_out' => 'ที่นั่งเต็ม',
            'seats_freed' => 'มีที่นั่งว่างคืน',
            'almost_ready' => 'ใกล้การันตีออก',
            'guaranteed' => 'การันตีออกเดินทาง',
            default => $type,
        };
    }
}
