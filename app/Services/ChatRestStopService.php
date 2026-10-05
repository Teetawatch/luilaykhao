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
 *
 * "นัดรวมพลล่วงหน้า" (kind = meetup) ใช้กลไกเดียวกัน แต่ตั้งเวลา/วันได้
 * (พรุ่งนี้ 04:30 หน้าลานกางเต็นท์), เตือนคืนก่อน 20:00 + ก่อนเวลา 15 นาที,
 * นับรวมคนจอยทริป (เจอกันที่หน้างานอยู่แล้ว) และไม่ปิดจุดพักที่ใช้งานอยู่
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
            // (นัดรวมพลล่วงหน้าไม่นับ — นัดพรุ่งนี้เช้าต้องอยู่รอดผ่านการแวะปั๊มวันนี้)
            ChatRestStop::where('schedule_id', $schedule->id)
                ->where('kind', ChatRestStop::KIND_REST)
                ->whereNull('departed_at')
                ->update(['departed_at' => now()]);

            $stop = ChatRestStop::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'kind' => ChatRestStop::KIND_REST,
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
     * นัดรวมพลล่วงหน้า — เช่น "พรุ่งนี้ 04:30 หน้าลานกางเต็นท์" ขึ้นดูพระอาทิตย์
     */
    public function openMeetup(User $staff, TripSchedule $schedule, Carbon $meetAt, ?string $place = null): ChatRestStop
    {
        if ($meetAt->lte(now())) {
            throw new \Exception('เวลานัดต้องเป็นเวลาข้างหน้า');
        }
        if ($meetAt->gt(now()->addDays(ChatRestStop::MEETUP_MAX_DAYS))) {
            throw new \Exception('นัดล่วงหน้าได้ไม่เกิน '.ChatRestStop::MEETUP_MAX_DAYS.' วัน');
        }

        $stop = DB::transaction(function () use ($staff, $schedule, $meetAt, $place) {
            $stop = ChatRestStop::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'kind' => ChatRestStop::KIND_MEETUP,
                'place' => $place ?: null,
                'return_at' => $meetAt,
                'reminded_at' => $this->minutesLeft($meetAt) <= ChatRestStop::MEETUP_REMIND_BEFORE_MINUTES ? now() : null,
            ]);

            $where = $place ? " ที่{$place}" : '';
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "📍 นัดรวมพล {$this->when($meetAt)} น.{$where} — ถึงจุดนัดแล้วกด \"มาถึงแล้ว\" ในการ์ดนี้",
            ]);

            $stop->update(['message_id' => $message->id]);

            return $stop;
        });

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
            throw new \Exception($stop->isMeetup() ? 'นัดนี้จบไปแล้ว' : 'รถออกไปแล้ว');
        }

        // กด "มาถึงแล้ว" ล่วงหน้าทั้งคืนไม่ได้ — ไม่งั้นรายชื่อตอนเช้าโกหก
        if ($boarded && ! $asStaff && $stop->isMeetup()
            && $stop->return_at->gt(now()->addHours(ChatRestStop::MEETUP_ARRIVE_WINDOW_HOURS))) {
            throw new \Exception('ยังไม่ถึงเวลานัด กดได้เมื่อถึงจุดนัดแล้วนะครับ');
        }

        $schedule = $stop->schedule;
        $roster = $this->rosterOf($stop);
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
            throw new \Exception($stop->isMeetup() ? 'นัดนี้จบไปแล้ว' : 'รถออกไปแล้ว');
        }

        $minutes = min(max($minutes, 1), 60);
        $base = $stop->return_at->isPast() ? now() : $stop->return_at;
        $returnAt = $base->copy()->addMinutes($minutes);

        $stop->update([
            'return_at' => $returnAt,
            // เหลือไม่ถึงช่วงเตือน = เพิ่งประกาศเวลาใหม่ ไม่ต้องเตือนซ้ำทันที
            'reminded_at' => $this->minutesLeft($returnAt) <= $stop->remindBeforeMinutes() ? now() : null,
            'due_notified_at' => null,
        ]);

        $fresh = $this->broadcastUpdate($stop);
        $this->chatService->postSystem(
            $stop->schedule,
            $stop->isMeetup()
                ? "⏰ เลื่อนเวลานัดรวมพลไป {$minutes} นาที — เจอกัน {$this->when($fresh->return_at)} น."
                : "⏰ ขยายเวลาพักอีก {$minutes} นาที — กลับขึ้นรถ {$this->clock($fresh->return_at)} น.",
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
        $complete = $total > 0 && $boarded >= $total;
        $this->chatService->postSystem(
            $stop->schedule,
            $stop->isMeetup()
                ? ($complete
                    ? "📍 มากันครบ {$total}/{$total} คน ไปกันเลย!"
                    : '📍 เริ่มแล้ว'.($total > 0 ? " (มาถึงจุดนัด {$boarded}/{$total} คน)" : ''))
                : ($complete
                    ? "🚐 ออกรถแล้ว — ขึ้นครบ {$total}/{$total} คน ไปต่อกันเลย!"
                    : '🚐 ออกรถแล้ว'.($total > 0 ? " (เช็คชื่อขึ้นรถ {$boarded}/{$total} คน)" : '')),
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

        // นัดรวมพลอาจอยู่ไกลถึงพรุ่งนี้ (เตือนคืนก่อน) — ดึงทุกใบที่ยังเปิดในสองวัน
        // ข้างหน้าแล้วให้ settleOne ตัดสินทีละใบ (ใบที่เปิดอยู่มีไม่กี่ใบเสมอ)
        ChatRestStop::whereNull('departed_at')
            ->whereNotNull('message_id')
            ->where('return_at', '<=', now()->addDays(2))
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

        if ($stop->isMeetup()) {
            $this->remindEve($stop);
        }

        $due = $stop->return_at->lte(now());
        if (! $due && $stop->return_at->gt(now()->addMinutes($stop->remindBeforeMinutes()))) {
            return;
        }
        if (($due && $stop->due_notified_at) || (! $due && $stop->reminded_at)) {
            return;
        }
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

        $place = $stop->place ? " ที่{$stop->place}" : '';

        foreach ($missing->pluck('user_id')->filter()->unique() as $userId) {
            $names = $missing->where('user_id', $userId)->pluck('name');
            $who = $names->count() > 1 ? ' ('.$names->join(', ').')' : '';
            $left = $this->minutesLeft($stop->return_at);

            $text = match (true) {
                $stop->isMeetup() && $due => ["📍 ถึงเวลานัดรวมพลแล้ว{$who}", "นัดกันไว้ {$time} น.{$place} ทุกคนรออยู่นะครับ · {$tripTitle}"],
                $stop->isMeetup() => ["⏰ อีก {$left} นาทีนัดรวมพล{$who}", "{$time} น.{$place} ถึงแล้วกด \"มาถึงแล้ว\" ในแชทด้วยนะครับ · {$tripTitle}"],
                $due => ["🚐 ถึงเวลากลับขึ้นรถแล้ว{$who}", "นัดกันไว้ {$time} น. ทุกคนรออยู่ที่รถนะครับ · {$tripTitle}"],
                default => ["⏰ อีก {$left} นาทีรถออก{$who}", "กลับขึ้นรถ {$time} น. แล้วกด \"ขึ้นรถแล้ว\" ในแชทด้วยนะครับ · {$tripTitle}"],
            };

            $this->send((int) $userId, $text, $data);
        }

        // ถึงเวลาแล้วยังไม่ครบ — บอกสตาฟว่าขาดใคร จะได้โทรตามได้ทันที
        if ($due) {
            $names = $missing->pluck('name');
            $list = $names->take(8)->join(', ').($names->count() > 8 ? ' และอีก '.($names->count() - 8).' คน' : '');
            $title = $stop->isMeetup()
                ? "📍 ถึงเวลานัด ยังไม่มา {$names->count()} คน"
                : "🚐 ถึงเวลาออกรถ ยังขาด {$names->count()} คน";

            foreach ($this->staffIds($schedule) as $staffId) {
                $this->send($staffId, [$title, $list], $data);
            }
        }
    }

    /**
     * เตือนคืนก่อนวันนัด 20:00 — ให้ทุกคนตั้งนาฬิกาปลุก (ตอนนั้นยังไม่มีใคร "มาถึง")
     * ส่งเฉพาะนัดที่ประกาศไว้ก่อนช่วงเตือน และยังเหลือเวลาเกินชั่วโมงก่อนนัด
     */
    private function remindEve(ChatRestStop $stop): void
    {
        if ($stop->eve_reminded_at) {
            return;
        }

        $eveAt = $stop->return_at->copy()->timezone('Asia/Bangkok')
            ->subDay()->setTime(ChatRestStop::MEETUP_EVE_HOUR, 0);

        if (now()->lt($eveAt) || $stop->created_at->gte($eveAt) || $stop->return_at->lte(now()->addHour())) {
            return;
        }

        $claimed = ChatRestStop::whereKey($stop->id)->whereNull('eve_reminded_at')->update(['eve_reminded_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $schedule = $stop->schedule;
        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $place = $stop->place ? " ที่{$stop->place}" : '';
        $data = [
            'type' => 'rest_stop',
            'route' => 'chat',
            'schedule_id' => (string) $schedule->id,
            'message_id' => (string) $stop->message_id,
        ];

        foreach ($this->rosterOf($stop)->pluck('user_id')->filter()->unique() as $userId) {
            $this->send((int) $userId, [
                "🌙 พรุ่งนี้ {$this->clock($stop->return_at)} น. นัดรวมพล",
                "เจอกัน{$place} — ตั้งนาฬิกาปลุกไว้ด้วยนะครับ · {$tripTitle}",
            ], $data);
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
        $roster = $this->rosterOf($stop);

        return [
            'id' => $stop->id,
            'kind' => $stop->kind ?: ChatRestStop::KIND_REST,
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

        return $this->rosterOf($stop)
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

    /**
     * รายชื่อของการ์ดนี้ — พักรถนับเฉพาะคนบนรถ ส่วนนัดรวมพลนับคนจอยทริปด้วย
     * (เจอกันที่หน้างานอยู่แล้ว)
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterOf(ChatRestStop $stop): Collection
    {
        return $this->roster->passengers($stop->schedule, includeJoinTrip: $stop->isMeetup());
    }

    /** "04:30" ถ้าเป็นวันนี้, "พรุ่งนี้ 04:30", หรือ "ศ. 9 ต.ค. 04:30" */
    private function when(Carbon $at): string
    {
        $local = $at->copy()->timezone('Asia/Bangkok');
        $today = now('Asia/Bangkok')->startOfDay();
        $day = $local->copy()->startOfDay();
        $time = $local->format('H:i');

        if ($day->equalTo($today)) {
            return "วันนี้ {$time}";
        }
        if ($day->equalTo($today->copy()->addDay())) {
            return "พรุ่งนี้ {$time}";
        }

        $days = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];
        $months = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

        return "{$days[$local->dayOfWeek]} {$local->day} {$months[$local->month]} {$time}";
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
