<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatRestStopUpdated;
use App\Events\ChatStopRequestsUpdated;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatRestStop;
use App\Models\ChatRestStopBoarding;
use App\Models\ChatStopRequest;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * จุดพักระหว่างทาง + เช็คชื่อขึ้นรถ + "ขอแวะห้องน้ำ" แบบไม่บอกชื่อ
 *
 * ปัญหาจริงหน้างาน: แวะปั๊ม/คาเฟ่แล้วมีคนกลับรถช้าทุกครั้ง สตาฟต้องนับหัวแล้ว
 * ไล่โทรตาม — การ์ดนี้บอกเวลากลับรถชัด ๆ เตือนคนที่ยังไม่ขึ้นก่อน 5 นาที และ
 * ให้สตาฟเห็นทันทีว่าขาดใคร พร้อมเบอร์โทร
 *
 * รายชื่ออิงผู้โดยสารจริง (TripRosterService) ไม่ใช่บัญชีแอป เพราะคนในกลุ่ม
 * หลายคนไม่มีแอป — คนที่จองให้กด "ขึ้นรถแล้ว" แทนทั้งกลุ่มได้ สตาฟติ๊กแทนใครก็ได้
 */
class ChatRestStopService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private FcmService $fcm,
    ) {}

    // ── จุดพัก ──────────────────────────────────────────────────────────────

    public function open(User $staff, TripSchedule $schedule, int $minutes, ?string $place = null): ChatRestStop
    {
        $minutes = min(max($minutes, ChatRestStop::MIN_MINUTES), ChatRestStop::MAX_MINUTES);
        $returnAt = now()->addMinutes($minutes);

        $stop = DB::transaction(function () use ($staff, $schedule, $returnAt, $place, $minutes) {
            // จุดพักเดิมที่ลืมกด "ออกรถ" — ปิดเงียบ ๆ ให้เหลือการ์ดที่ใช้งานใบเดียว
            ChatRestStop::where('schedule_id', $schedule->id)
                ->whereNull('departed_at')
                ->update(['departed_at' => now()]);

            $stop = ChatRestStop::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'place' => $place ?: null,
                'return_at' => $returnAt,
                // พักสั้นกว่าช่วงเตือน — เพิ่งประกาศเวลาไป ไม่ต้องเตือนซ้ำ
                'reminded_at' => $minutes <= ChatRestStop::REMIND_BEFORE_MINUTES ? now() : null,
            ]);

            $time = $this->clock($returnAt);
            $where = $place ? " ที่{$place}" : '';

            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "🅿️ พัก {$minutes} นาที{$where} — กลับขึ้นรถ {$time} น. กด \"ขึ้นรถแล้ว\" ในการ์ดนี้เมื่อกลับมาถึงรถ",
            ]);

            $stop->update(['message_id' => $message->id]);

            return $stop;
        });

        // แวะให้แล้ว — คำขอแวะห้องน้ำที่ค้างอยู่ถือว่าได้รับการตอบแล้ว
        $this->resolveRequests($schedule, null, announce: false);

        $message = $stop->message()->with(['user', 'replyTo.user', 'reactions', 'restStop.boardings'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $stop->fresh('boardings');
    }

    /**
     * ติ๊กขึ้นรถ/ยังไม่ขึ้น — ลูกทริปติ๊กได้เฉพาะคนที่ตัวเองดูแล สตาฟติ๊กได้ทุกคน
     *
     * @param  array<int, int>  $passengerIds
     */
    public function setBoarded(User $user, ChatRestStop $stop, array $passengerIds, bool $boarded, bool $asStaff): ChatRestStop
    {
        if ($stop->isDeparted()) {
            throw new \Exception('รถออกไปแล้ว');
        }

        $schedule = $stop->schedule;
        $roster = $this->roster->passengers($schedule, includeJoinTrip: false);
        $allowed = $asStaff
            ? $roster->pluck('passenger_id')
            : $roster->where('user_id', $user->id)->pluck('passenger_id');

        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $allowed->contains($id))
            ->values();

        if ($ids->isEmpty()) {
            throw new \Exception($asStaff ? 'ไม่พบผู้โดยสารในรอบนี้' : 'ติ๊กได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }

        DB::transaction(function () use ($stop, $ids, $boarded, $user) {
            if (! $boarded) {
                ChatRestStopBoarding::where('stop_id', $stop->id)->whereIn('passenger_id', $ids)->delete();

                return;
            }

            foreach ($ids as $id) {
                ChatRestStopBoarding::firstOrCreate(
                    ['stop_id' => $stop->id, 'passenger_id' => $id],
                    ['marked_by_id' => $user->id],
                );
            }
        });

        return $this->broadcastUpdate($stop);
    }

    /** ขยายเวลาพัก — นับต่อจากเวลานัดเดิม หรือจากตอนนี้ถ้าเลยเวลาไปแล้ว */
    public function extend(ChatRestStop $stop, int $minutes): ChatRestStop
    {
        if ($stop->isDeparted()) {
            throw new \Exception('รถออกไปแล้ว');
        }

        $minutes = min(max($minutes, 1), 60);
        $base = $stop->return_at->isPast() ? now() : $stop->return_at;
        $returnAt = $base->copy()->addMinutes($minutes);

        $stop->update([
            'return_at' => $returnAt,
            // เหลือไม่ถึงช่วงเตือน = เพิ่งประกาศเวลาใหม่ ไม่ต้องเตือนซ้ำทันที
            'reminded_at' => $this->minutesLeft($returnAt) <= ChatRestStop::REMIND_BEFORE_MINUTES ? now() : null,
            'due_notified_at' => null,
        ]);

        $fresh = $this->broadcastUpdate($stop);
        $this->chatService->postSystem(
            $stop->schedule,
            "⏰ ขยายเวลาพักอีก {$minutes} นาที — กลับขึ้นรถ {$this->clock($fresh->return_at)} น.",
        );

        return $fresh;
    }

    /** ออกรถ — ปิดการ์ด แล้วบอกห้องว่าขึ้นครบไหม */
    public function depart(ChatRestStop $stop): ChatRestStop
    {
        if ($stop->isDeparted()) {
            return $stop->fresh('boardings');
        }

        $stop->update(['departed_at' => now()]);
        $fresh = $this->broadcastUpdate($stop);

        [$total, $boarded] = $this->counts($fresh);
        $this->chatService->postSystem(
            $stop->schedule,
            $total > 0 && $boarded >= $total
                ? "🚐 ออกรถแล้ว — ขึ้นครบ {$total}/{$total} คน ไปต่อกันเลย!"
                : '🚐 ออกรถแล้ว'.($total > 0 ? " (เช็คชื่อขึ้นรถ {$boarded}/{$total} คน)" : ''),
        );

        return $fresh;
    }

    /**
     * งานตามเวลา (ทุกนาที): เตือนคนที่ยังไม่ขึ้นรถก่อน 5 นาที, ถึงเวลาแล้วเตือนอีกรอบ
     * พร้อมบอกสตาฟว่าขาดใคร, และปิดการ์ดที่ลืมกด "ออกรถ" นานเกินไป
     */
    public function settleDue(): int
    {
        $handled = 0;

        ChatRestStop::whereNull('departed_at')
            ->whereNotNull('message_id')
            ->where('return_at', '<=', now()->addMinutes(ChatRestStop::REMIND_BEFORE_MINUTES))
            ->where(fn ($q) => $q->whereNull('reminded_at')
                ->orWhere(fn ($w) => $w->whereNull('due_notified_at')->where('return_at', '<=', now()))
                ->orWhere('return_at', '<=', now()->subMinutes(ChatRestStop::AUTO_DEPART_AFTER_MINUTES)))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (ChatRestStop $stop) use (&$handled) {
                try {
                    $this->settleOne($stop);
                    $handled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: จุดพักทำงานไม่สำเร็จ', [
                        'stop_id' => $stop->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $handled;
    }

    private function settleOne(ChatRestStop $stop): void
    {
        if ($stop->return_at->lte(now()->subMinutes(ChatRestStop::AUTO_DEPART_AFTER_MINUTES))) {
            $stop->update(['departed_at' => now()]);
            $this->broadcastUpdate($stop);

            return;
        }

        $due = $stop->return_at->lte(now());
        $column = $due ? 'due_notified_at' : 'reminded_at';

        // จองสิทธิ์ก่อนส่ง — job ซ้อนกันหรือรันซ้ำจะไม่เด้งซ้ำ
        $claimed = ChatRestStop::whereKey($stop->id)->whereNull($column)
            ->update($due ? ['due_notified_at' => now(), 'reminded_at' => now()] : ['reminded_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $schedule = $stop->schedule;
        $missing = $this->missing($stop);
        if ($missing->isEmpty()) {
            return;
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $time = $this->clock($stop->return_at);
        $data = [
            'type' => 'rest_stop',
            'route' => 'chat',
            'schedule_id' => (string) $schedule->id,
            'message_id' => (string) $stop->message_id,
        ];

        foreach ($missing->pluck('user_id')->filter()->unique() as $userId) {
            $names = $missing->where('user_id', $userId)->pluck('name');
            $who = $names->count() > 1 ? ' ('.$names->join(', ').')' : '';

            $this->send((int) $userId, $due
                ? ["🚐 ถึงเวลากลับขึ้นรถแล้ว{$who}", "นัดกันไว้ {$time} น. ทุกคนรออยู่ที่รถนะครับ · {$tripTitle}"]
                : ["⏰ อีก {$this->minutesLeft($stop->return_at)} นาทีรถออก{$who}", "กลับขึ้นรถ {$time} น. แล้วกด \"ขึ้นรถแล้ว\" ในแชทด้วยนะครับ · {$tripTitle}"],
                $data);
        }

        // ถึงเวลาแล้วยังไม่ครบ — บอกสตาฟว่าขาดใคร จะได้โทรตามได้ทันที
        if ($due) {
            $names = $missing->pluck('name');
            $list = $names->take(8)->join(', ').($names->count() > 8 ? ' และอีก '.($names->count() - 8).' คน' : '');

            foreach ($this->staffIds($schedule) as $staffId) {
                $this->send($staffId, ["🚐 ถึงเวลาออกรถ ยังขาด {$names->count()} คน", $list], $data);
            }
        }
    }

    /**
     * payload การ์ด — ไม่ผูกผู้ดู (broadcast ไปทั้งห้อง) จึงไม่มีเบอร์โทร
     * สตาฟขอเบอร์ผ่าน [withPhones]
     *
     * @return array<string, mixed>
     */
    public function present(ChatRestStop $stop, bool $withPhones = false): array
    {
        $stop->loadMissing('boardings');
        $boardedIds = $stop->boardings->pluck('passenger_id')->map(fn ($id) => (int) $id);
        $roster = $this->roster->passengers($stop->schedule, includeJoinTrip: false);

        return [
            'id' => $stop->id,
            'place' => $stop->place,
            'return_at' => $stop->return_at?->toISOString(),
            'departed_at' => $stop->departed_at?->toISOString(),
            'is_departed' => $stop->isDeparted(),
            'total' => $roster->count(),
            'boarded_count' => $roster->whereIn('passenger_id', $boardedIds)->count(),
            'passengers' => $roster->map(fn ($p) => [
                'id' => $p['passenger_id'],
                'name' => $p['name'],
                'user_id' => $p['user_id'],
                'booking_id' => $p['booking_id'],
                'boarded' => $boardedIds->contains($p['passenger_id']),
                ...($withPhones ? ['phone' => $p['phone']] : []),
            ])->values()->all(),
        ];
    }

    // ── ขอแวะห้องน้ำ ────────────────────────────────────────────────────────

    /**
     * ส่งคำขอ (ซ้ำได้ = อัปเดตความด่วนของคำขอเดิม) แล้วบอกสตาฟ
     * สตาฟเห็นแค่จำนวน ไม่มีทางรู้ว่าใครกด
     */
    public function requestStop(User $user, TripSchedule $schedule, bool $urgent): array
    {
        $existing = ChatStopRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->first();

        // กดซ้ำโดยไม่ได้เร่งขึ้น = ไม่ต้องเด้งหาสตาฟอีก
        $shouldNotify = ! $existing || ($urgent && ! $existing->urgent);

        if ($existing) {
            $existing->update(['urgent' => $existing->urgent || $urgent]);
        } else {
            ChatStopRequest::create([
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'urgent' => $urgent,
            ]);
        }

        $summary = $this->requestSummary($schedule);
        broadcast(new ChatStopRequestsUpdated($schedule->id, $summary));

        if ($shouldNotify) {
            $title = $urgent ? '🚻 มีคนขอแวะห้องน้ำ (ด่วน)' : '🚻 มีคนขอแวะห้องน้ำ';
            $body = "รวมตอนนี้ {$summary['pending']} คน"
                .($summary['urgent'] > 0 ? " · ด่วน {$summary['urgent']}" : '')
                .' — เปิดแชทเพื่อกดรับทราบ';

            foreach ($this->staffIds($schedule) as $staffId) {
                $this->send($staffId, [$title, $body], [
                    'type' => 'stop_request',
                    'route' => 'chat',
                    'schedule_id' => (string) $schedule->id,
                ], tag: "stop-request-{$schedule->id}");
            }
        }

        return $summary;
    }

    public function cancelRequest(User $user, TripSchedule $schedule): array
    {
        ChatStopRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->delete();

        $summary = $this->requestSummary($schedule);
        broadcast(new ChatStopRequestsUpdated($schedule->id, $summary));

        return $summary;
    }

    /**
     * สตาฟรับทราบ — ปิดคำขอที่ค้างทั้งหมด บอกห้องว่าจะแวะในกี่นาที
     * และแจ้งคนที่ขอเป็นรายคน (ห้องรวมไม่รู้ว่าใครขอ)
     */
    public function acknowledge(TripSchedule $schedule, ?int $minutes): array
    {
        $this->resolveRequests($schedule, $minutes, announce: true);

        return $this->requestSummary($schedule);
    }

    /**
     * @return array{pending: int, urgent: int}
     */
    public function requestSummary(TripSchedule $schedule): array
    {
        $pending = ChatStopRequest::where('schedule_id', $schedule->id)->whereNull('acknowledged_at');

        return [
            'pending' => (clone $pending)->count(),
            'urgent' => (clone $pending)->where('urgent', true)->count(),
        ];
    }

    public function hasPendingRequest(User $user, TripSchedule $schedule): bool
    {
        return ChatStopRequest::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->exists();
    }

    /**
     * ช่วงที่ปุ่มขอแวะห้องน้ำมีความหมาย — วันก่อนเดินทางถึงวันหลังกลับ เหมือนแชร์ตำแหน่ง
     */
    public function isWithinTripWindow(TripSchedule $schedule): bool
    {
        return app(TripMemberLocationService::class)->isWithinWindow($schedule);
    }

    private function resolveRequests(TripSchedule $schedule, ?int $minutes, bool $announce): void
    {
        $requesterIds = ChatStopRequest::where('schedule_id', $schedule->id)
            ->whereNull('acknowledged_at')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($requesterIds->isEmpty()) {
            return;
        }

        ChatStopRequest::where('schedule_id', $schedule->id)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]);

        broadcast(new ChatStopRequestsUpdated($schedule->id, $this->requestSummary($schedule)));

        $when = match (true) {
            $minutes === null => 'เร็ว ๆ นี้',
            $minutes <= 0 => 'ตอนนี้เลย',
            default => "ในอีกประมาณ {$minutes} นาที",
        };

        if ($announce) {
            $this->chatService->postSystem($schedule, "🚻 ทีมงานรับทราบคำขอแวะห้องน้ำแล้ว — จะแวะ{$when}ครับ");
        }

        foreach ($requesterIds as $userId) {
            $this->send($userId, ['🚻 ทีมงานรับทราบแล้ว', $announce ? "จะแวะห้องน้ำ{$when}ครับ" : 'รถจอดพักแล้ว ดูเวลากลับรถในแชทได้เลย'], [
                'type' => 'stop_request_ack',
                'route' => 'chat',
                'schedule_id' => (string) $schedule->id,
            ]);
        }
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    /** @return array{0: int, 1: int} [ทั้งหมด, ขึ้นแล้ว] */
    private function counts(ChatRestStop $stop): array
    {
        $p = $this->present($stop);

        return [$p['total'], $p['boarded_count']];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function missing(ChatRestStop $stop): Collection
    {
        $boarded = $stop->boardings()->pluck('passenger_id')->map(fn ($id) => (int) $id);

        return $this->roster->passengers($stop->schedule, includeJoinTrip: false)
            ->reject(fn ($p) => $boarded->contains($p['passenger_id']))
            ->values();
    }

    /** @return Collection<int, int> */
    private function staffIds(TripSchedule $schedule): Collection
    {
        return $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    /**
     * @param  array{0: string, 1: string}  $text
     * @param  array<string, string>  $data
     */
    private function send(int $userId, array $text, array $data, ?string $tag = null): void
    {
        try {
            $this->fcm->sendToUser($userId, $text[0], $text[1], $data, array_filter([
                'tag' => $tag,
                'android_channel' => SendChatPushJob::ANDROID_CHANNEL,
            ]));
        } catch (\Throwable $e) {
            Log::warning('ChatRestStopService: ส่ง push ไม่สำเร็จ', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function broadcastUpdate(ChatRestStop $stop): ChatRestStop
    {
        $fresh = $stop->fresh('boardings');

        broadcast(new ChatRestStopUpdated(
            (int) $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }

    private function minutesLeft(Carbon $at): int
    {
        return max(1, (int) ceil(($at->getTimestamp() - now()->getTimestamp()) / 60));
    }

    private function clock(Carbon $at): string
    {
        return $at->copy()->timezone('Asia/Bangkok')->format('H:i');
    }
}
