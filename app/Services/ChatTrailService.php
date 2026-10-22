<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatTrailCheckUpdated;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatTrailCheck;
use App\Models\ChatTrailPosition;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เช็คพอยต์บนเส้นทางเดิน — "ใครถึงไหนแล้ว"
 *
 * ปัญหาจริง: เดินขึ้นยอดแล้วกลุ่มแตกเป็นหลายช่วง สตาฟปิดท้ายไม่รู้ว่าข้างหน้าเหลือ
 * กี่คน ต้องวิทยุถามกันวุ่น ถึงยอดก็ต้องนับหัวใหม่ — การ์ดนี้ให้ทุกคนกด "ถึงแล้ว"
 * ที่แต่ละจุด (คนจองกดแทนกลุ่มได้ สตาฟกดแทนใครก็ได้) แล้วบอกว่าใครยังอยู่ท้ายสุด
 *
 * "ตามไม่ทัน ขอพัก" ส่งตรงถึงทีมงานพร้อมชื่อ (ต้องรู้ว่าไปหาใคร) แต่ไม่ขึ้นในห้องรวม
 * — คนที่เหนื่อยไม่ควรต้องประกาศให้ทั้งกลุ่มรู้ payload การ์ดจึงมีแค่จำนวน
 */
class ChatTrailService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private FcmService $fcm,
    ) {}

    /**
     * @param  array<int, string>  $checkpoints  ชื่อจุดเรียงตามลำดับเดิน
     */
    public function open(User $staff, TripSchedule $schedule, ?string $title, array $checkpoints): ChatTrailCheck
    {
        $names = collect($checkpoints)
            ->map(fn ($n) => trim((string) $n))
            ->filter(fn ($n) => $n !== '')
            ->values();

        if ($names->count() < ChatTrailCheck::MIN_CHECKPOINTS || $names->count() > ChatTrailCheck::MAX_CHECKPOINTS) {
            throw new \Exception('ตั้งจุดได้ '.ChatTrailCheck::MIN_CHECKPOINTS.'–'.ChatTrailCheck::MAX_CHECKPOINTS.' จุด');
        }
        if ($names->unique()->count() !== $names->count()) {
            throw new \Exception('ชื่อจุดซ้ำกัน ตั้งชื่อให้ต่างกันหน่อยครับ');
        }
        if ($this->roster->travelling($schedule)->isEmpty()) {
            throw new \Exception('ยังไม่มีผู้เดินทางในรอบนี้');
        }

        $check = DB::transaction(function () use ($staff, $schedule, $title, $names) {
            // เช็คพอยต์ของช่วงก่อนที่ลืมปิด — ปิดเงียบ ๆ ให้เหลือการ์ดที่ใช้งานใบเดียว
            ChatTrailCheck::where('schedule_id', $schedule->id)
                ->whereNull('closed_at')
                ->update(['closed_at' => now()]);

            $check = ChatTrailCheck::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'title' => $title ?: null,
                'checkpoints' => $names->all(),
            ]);

            $route = $names->join(' → ');
            $heading = $title ? "{$title}: " : '';

            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "🏔️ เช็คพอยต์ — {$heading}{$route} ถึงแต่ละจุดแล้วกด \"ถึงแล้ว\" ในการ์ดนี้ "
                    .'ตามไม่ทันกด "ขอพัก" ทีมงานเห็นคนเดียว',
            ]);

            $check->update(['message_id' => $message->id]);

            return $check;
        });

        $message = $check->message()->with(['user', 'replyTo.user', 'reactions', 'trailCheck.positions'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $check->fresh('positions');
    }

    /**
     * ตั้งจุดที่ถึงแล้ว — [index] null = ยังไม่ถึงจุดแรก (แก้กดผิด), ถอยหลังได้
     * ลูกทริปตั้งได้เฉพาะคนที่ตัวเองดูแล สตาฟตั้งได้ทุกคน
     *
     * @param  array<int, int>  $passengerIds
     */
    public function reach(User $user, ChatTrailCheck $check, array $passengerIds, ?int $index, bool $asStaff): ChatTrailCheck
    {
        if ($check->isClosed()) {
            throw new \Exception('ปิดเช็คพอยต์นี้ไปแล้ว');
        }
        if ($index !== null && ($index < 0 || $index > $check->lastIndex())) {
            throw new \Exception('ไม่พบจุดนี้ในเส้นทาง');
        }

        $ids = $this->allowedIds($user, $check, $passengerIds, $asStaff);

        DB::transaction(function () use ($check, $ids, $index, $user) {
            foreach ($ids as $id) {
                $position = ChatTrailPosition::firstOrNew(['check_id' => $check->id, 'passenger_id' => $id]);
                if ($position->exists && $position->reached_index === $index) {
                    continue;
                }

                $position->fill([
                    'reached_index' => $index,
                    'reached_at' => $index === null ? null : now(),
                    'marked_by_id' => $user->id,
                ]);

                // เดินต่อไปถึงจุดถัดไปได้แล้ว = หายเหนื่อยแล้ว คำขอพักเดิมหมดความหมาย
                if ($index !== null && $position->needsHelp()) {
                    $position->help_acked_at = now();
                }

                $position->save();
            }
        });

        $this->finishIfComplete($check);

        return $this->broadcastUpdate($check);
    }

    /**
     * "ตามไม่ทัน ขอพัก" — ส่งตรงถึงทีมงานพร้อมชื่อและจุดล่าสุดที่ถึง
     * กดซ้ำภายใน HELP_REPEAT_MINUTES = คำขอเดิม ไม่เด้งซ้ำ
     *
     * @param  array<int, int>  $passengerIds
     */
    public function requestHelp(User $user, ChatTrailCheck $check, array $passengerIds, ?string $note): ChatTrailCheck
    {
        if ($check->isClosed()) {
            throw new \Exception('ปิดเช็คพอยต์นี้ไปแล้ว ทักทีมงานในแชทหรือโทรหาได้เลยครับ');
        }

        $ids = $this->allowedIds($user, $check, $passengerIds, asStaff: false);
        $note = $note !== null ? trim($note) : null;

        $notify = collect();
        DB::transaction(function () use ($check, $ids, $note, &$notify) {
            foreach ($ids as $id) {
                $position = ChatTrailPosition::firstOrNew(['check_id' => $check->id, 'passenger_id' => $id]);
                $repeat = $position->needsHelp()
                    && $position->help_at->gt(now()->subMinutes(ChatTrailCheck::HELP_REPEAT_MINUTES));

                $position->fill([
                    'help_at' => $repeat ? $position->help_at : now(),
                    'help_note' => $note ?: ($repeat ? $position->help_note : null),
                    'help_acked_at' => null,
                ]);
                $position->save();

                if (! $repeat) {
                    $notify->push($id);
                }
            }
        });

        if ($notify->isNotEmpty()) {
            $this->notifyStaffOfHelp($check, $notify, $note);
        }

        return $this->broadcastUpdate($check);
    }

    /** ถอนคำขอพัก (ตามทันแล้ว) — ของทุกคนที่ตัวเองดูแล */
    public function cancelHelp(User $user, ChatTrailCheck $check): ChatTrailCheck
    {
        $mine = $this->rosterOf($check)->where('user_id', $user->id)->pluck('passenger_id');

        ChatTrailPosition::where('check_id', $check->id)
            ->whereIn('passenger_id', $mine)
            ->whereNotNull('help_at')
            ->whereNull('help_acked_at')
            ->update(['help_acked_at' => now()]);

        return $this->broadcastUpdate($check);
    }

    /**
     * ทีมงานรับทราบคำขอพัก — แจ้งคนดูแลของคนนั้นว่ามีคนรู้แล้ว
     *
     * @param  array<int, int>  $passengerIds
     */
    public function acknowledgeHelp(User $staff, ChatTrailCheck $check, array $passengerIds): ChatTrailCheck
    {
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()->values();

        $pending = ChatTrailPosition::where('check_id', $check->id)
            ->whereIn('passenger_id', $ids)
            ->whereNotNull('help_at')
            ->whereNull('help_acked_at')
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id);

        if ($pending->isEmpty()) {
            return $this->broadcastUpdate($check);
        }

        ChatTrailPosition::where('check_id', $check->id)
            ->whereIn('passenger_id', $pending)
            ->update(['help_acked_at' => now()]);

        $roster = $this->rosterOf($check);
        $staffName = $staff->nickname ?: $staff->name;
        foreach ($roster->whereIn('passenger_id', $pending->all())->groupBy('user_id') as $userId => $people) {
            if (! $userId) {
                continue;
            }
            $this->send((int) $userId, [
                '🙋 ทีมงานรับทราบแล้ว',
                "{$staffName} รู้แล้วว่า".$people->pluck('name')->join(', ').'ขอพัก — พักตรงนั้นได้เลย ไม่ต้องรีบนะครับ',
            ], $this->pushData($check, 'trail_help_ack'));
        }

        return $this->broadcastUpdate($check);
    }

    public function close(ChatTrailCheck $check): ChatTrailCheck
    {
        $claimed = ChatTrailCheck::whereKey($check->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        if ($claimed === 0) {
            return $check->fresh('positions');
        }

        $fresh = $this->broadcastUpdate($check);
        $p = $this->present($fresh);
        $last = $fresh->checkpointNames()[$fresh->lastIndex()] ?? 'จุดสุดท้าย';
        $done = $p['counts'][$fresh->lastIndex()] ?? 0;

        $this->chatService->postSystem(
            $fresh->schedule,
            $p['total'] > 0 && $done >= $p['total']
                ? "🏔️ ปิดเช็คพอยต์ — ถึง{$last}ครบ {$p['total']}/{$p['total']} คน"
                : "🏔️ ปิดเช็คพอยต์ — ถึง{$last} {$done}/{$p['total']} คน",
        );

        return $fresh;
    }

    /** งานตามเวลา: ปิดการ์ดที่ลืมปิด */
    public function settleDue(): int
    {
        $handled = 0;

        ChatTrailCheck::whereNull('closed_at')
            ->where('created_at', '<=', now()->subHours(ChatTrailCheck::AUTO_CLOSE_AFTER_HOURS))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ChatTrailCheck $check) use (&$handled) {
                try {
                    $this->close($check);
                    $handled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: ปิดเช็คพอยต์ไม่สำเร็จ', ['check_id' => $check->id, 'error' => $e->getMessage()]);
                }
            });

        return $handled;
    }

    /**
     * payload การ์ด — ไม่ผูกผู้ดู: ตำแหน่งของทุกคนเปิดเผยได้ (เหมือนเช็คชื่อขึ้นรถ)
     * แต่ใครขอพักบอกแค่จำนวน รายชื่อ + เบอร์ ทีมงานได้ผ่าน [withStaffDetail]
     *
     * @return array<string, mixed>
     */
    public function present(ChatTrailCheck $check, bool $withStaffDetail = false, ?int $viewerId = null): array
    {
        $check->loadMissing('positions');
        $positions = $check->positions->keyBy('passenger_id');
        $roster = $this->rosterOf($check);
        $names = $check->checkpointNames();

        $people = $roster->map(function ($p) use ($positions, $withStaffDetail) {
            $pos = $positions->get($p['passenger_id']);

            return [
                'id' => $p['passenger_id'],
                'name' => $p['name'],
                'user_id' => $p['user_id'],
                'index' => $pos?->reached_index,
                'reached_at' => $pos?->reached_at?->toISOString(),
                ...($withStaffDetail ? [
                    'phone' => $p['phone'],
                    'needs_help' => (bool) $pos?->needsHelp(),
                    'help_at' => $pos?->needsHelp() ? $pos->help_at->toISOString() : null,
                    'help_note' => $pos?->needsHelp() ? $pos->help_note : null,
                ] : []),
            ];
        })->values();

        $counts = [];
        foreach (array_keys($names) as $i) {
            $counts[$i] = $people->filter(fn ($p) => $p['index'] !== null && $p['index'] >= $i)->count();
        }

        // คนท้ายสุด = จุดที่ถึงต่ำสุด (ยังไม่ถึงจุดแรก = -1) — ถ้าทุกคนถึงจุดสุดท้ายแล้วไม่มี
        $lowest = $people->map(fn ($p) => $p['index'] ?? -1)->min();
        $tail = ($people->isEmpty() || $lowest >= count($names) - 1)
            ? []
            : $people->filter(fn ($p) => ($p['index'] ?? -1) === $lowest)->pluck('name')->values()->all();

        $helpIds = $positions->filter(fn (ChatTrailPosition $pos) => $pos->needsHelp())->keys()
            ->map(fn ($id) => (int) $id)
            ->intersect($roster->pluck('passenger_id'));

        return [
            'id' => $check->id,
            'title' => $check->title,
            'checkpoints' => $names,
            'is_closed' => $check->isClosed(),
            'total' => $people->count(),
            'counts' => $counts,
            'tail_index' => $people->isEmpty() ? null : $lowest,
            'tail_names' => $tail,
            'help_count' => $helpIds->count(),
            // ฉันขอพักค้างอยู่ไหม — มีเฉพาะเมื่อรู้ว่าใครดู (โหลดห้อง/ตอบกลับการกด)
            // broadcast ไม่มีค่านี้ แอปคงค่าเดิมไว้
            ...($viewerId !== null ? [
                'my_help' => $roster->where('user_id', $viewerId)->pluck('passenger_id')->intersect($helpIds)->isNotEmpty(),
            ] : []),
            'passengers' => $people->all(),
            'created_at' => $check->created_at?->toISOString(),
        ];
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    private function finishIfComplete(ChatTrailCheck $check): void
    {
        $p = $this->present($check->fresh('positions'));
        $last = $check->lastIndex();
        if ($p['total'] === 0 || ($p['counts'][$last] ?? 0) < $p['total']) {
            return;
        }

        $claimed = ChatTrailCheck::whereKey($check->id)->whereNull('finished_at')->update(['finished_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $name = $check->checkpointNames()[$last];
        $this->chatService->postSystem($check->schedule, "🏔️ ถึง{$name}ครบ {$p['total']}/{$p['total']} คนแล้ว เก่งมากทุกคน! 🎉");

        foreach ($this->staffIds($check->schedule) as $staffId) {
            $this->send($staffId, ["🏔️ ถึง{$name}ครบทุกคนแล้ว", "{$p['total']} คน · ".($check->schedule->trip?->title ?? '')], $this->pushData($check, 'trail_done'));
        }
    }

    /** @param Collection<int, int> $passengerIds */
    private function notifyStaffOfHelp(ChatTrailCheck $check, Collection $passengerIds, ?string $note): void
    {
        $check->load('positions');
        $positions = $check->positions->keyBy('passenger_id');
        $people = $this->rosterOf($check)->whereIn('passenger_id', $passengerIds->all());
        if ($people->isEmpty()) {
            return;
        }

        $names = $check->checkpointNames();
        $index = $people->map(fn ($p) => $positions->get($p['passenger_id'])?->reached_index ?? -1)->min();
        $where = $index >= 0 ? "ล่าสุดถึง {$names[$index]}" : "ยังไม่ถึง {$names[0]}";

        $title = '🙋 '.$people->pluck('name')->join(', ').' ขอพัก ตามไม่ทัน';
        $body = $where.($note ? " · \"{$note}\"" : '').' — เปิดแชทเพื่อกดรับทราบ';

        foreach ($this->staffIds($check->schedule) as $staffId) {
            $this->send($staffId, [$title, $body], $this->pushData($check, 'trail_help'), tag: "trail-help-{$check->id}", channel: 'important_updates', staffAlert: true);
        }
    }

    /**
     * @param  array<int, int>  $passengerIds
     * @return Collection<int, int>
     */
    private function allowedIds(User $user, ChatTrailCheck $check, array $passengerIds, bool $asStaff): Collection
    {
        $roster = $this->rosterOf($check);
        $allowed = $asStaff
            ? $roster->pluck('passenger_id')
            : $roster->where('user_id', $user->id)->pluck('passenger_id');

        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $allowed->contains($id))
            ->values();

        if ($ids->isEmpty()) {
            throw new \Exception($asStaff ? 'ไม่พบผู้เดินทางในรอบนี้' : 'กดได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }

        return $ids;
    }

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $rosterCache = [];

    /**
     * คนที่เดินจริง — รวมคนจอยทริป (เดินด้วยกันแม้มาเอง) ไม่นับคนที่แจ้งว่าไปไม่ได้
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rosterOf(ChatTrailCheck $check): Collection
    {
        return $this->rosterCache[$check->schedule_id] ??= $this->roster->travelling($check->schedule);
    }

    /** @return Collection<int, int> */
    private function staffIds(TripSchedule $schedule): Collection
    {
        return $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    /** @return array<string, string> */
    private function pushData(ChatTrailCheck $check, string $type): array
    {
        return [
            'type' => $type,
            'route' => 'chat',
            'schedule_id' => (string) $check->schedule_id,
            'message_id' => (string) $check->message_id,
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $text
     * @param  array<string, string>  $data
     */
    /** @param  bool  $staffAlert  ทีมงานต้องลุกมาจัดการ — สั่นยาว ทะลุโหมดนอน (ดู FcmService::sendToUser) */
    private function send(int $userId, array $text, array $data, ?string $tag = null, string $channel = SendChatPushJob::ANDROID_CHANNEL, bool $staffAlert = false): void
    {
        try {
            $this->fcm->sendToUser($userId, $text[0], $text[1], $data, array_filter([
                'tag' => $tag,
                'android_channel' => $channel,
                'staff_alert' => $staffAlert,
            ]));
        } catch (\Throwable $e) {
            Log::warning('ChatTrailService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function broadcastUpdate(ChatTrailCheck $check): ChatTrailCheck
    {
        $fresh = $check->fresh('positions');

        broadcast(new ChatTrailCheckUpdated(
            (int) $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }
}
