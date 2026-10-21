<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatWellnessUpdated;
use App\Jobs\SendChatPushJob;
use App\Models\ChatMessage;
use App\Models\ChatWellnessAnswer;
use App\Models\ChatWellnessCheck;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เช็คอาการระหว่างทริป — "ยังไหวไหม"
 *
 * ปัญหาจริง: คนที่เหนื่อยจัดหรือเริ่มไม่สบายมักไม่กล้าบอก กลัวเป็นภาระกลุ่ม กว่าจะรู้
 * ก็หนักแล้ว — ก่อนช่วงโหด ทีมงานถามทั้งกลุ่มทีเดียว แต่ละคนตอบ สบาย / เหนื่อย /
 * ไม่ค่อยไหว ใครตอบ "ไม่ค่อยไหว" ทีมงานรู้ทันที ใครเงียบ 10 นาทีโดนถามซ้ำและ
 * ทีมงานได้รายชื่อ
 *
 * ความเป็นส่วนตัว: ห้องรวมเห็นแค่ "ตอบแล้วกี่คน" — คำตอบของใครเป็นข้อมูลสุขภาพ
 * เห็นแค่ทีมงานกับบัญชีที่ดูแลคนนั้น (เหมือนขอยา ChatSupplyService)
 */
class ChatWellnessService
{
    public function __construct(
        private ChatService $chatService,
        private TripRosterService $roster,
        private FcmService $fcm,
    ) {}

    public function open(User $staff, TripSchedule $schedule, ?string $label): ChatWellnessCheck
    {
        if ($this->roster->travelling($schedule)->isEmpty()) {
            throw new \Exception('ยังไม่มีผู้เดินทางในรอบนี้');
        }

        $check = DB::transaction(function () use ($staff, $schedule, $label) {
            // ใบก่อนหน้าที่ยังเปิด — อาการเมื่อกี้ไม่ใช่อาการตอนนี้ ปิดเงียบ ๆ
            ChatWellnessCheck::where('schedule_id', $schedule->id)
                ->whereNull('closed_at')
                ->update(['closed_at' => now()]);

            $check = ChatWellnessCheck::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $staff->id,
                'label' => $label ?: null,
            ]);

            $when = $label ? " ({$label})" : '';
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $staff->id,
                'sender_role' => $this->chatService->senderRole($staff, $schedule),
                'body' => "❤️ เช็คอาการ{$when} — ตอบในการ์ดนี้ว่ายังไหวไหม 👍 สบาย / 😮‍💨 เหนื่อย / 🤕 ไม่ค่อยไหว "
                    .'คำตอบเห็นแค่ทีมงาน ไม่ต้องเกรงใจนะครับ',
            ]);

            $check->update(['message_id' => $message->id]);

            return $check;
        });

        $message = $check->message()->with(['user', 'replyTo.user', 'reactions', 'wellnessCheck.answers'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $check->fresh('answers');
    }

    /**
     * ตอบ (เปลี่ยนคำตอบได้จนกว่าจะปิด) — ลูกทริปตอบได้เฉพาะคนที่ตัวเองดูแล สตาฟตอบแทนได้
     * เปลี่ยนเป็น "ไม่ค่อยไหว" = เด้งหาทีมงานทันที
     *
     * @param  array<int, int>  $passengerIds
     */
    public function answer(User $user, ChatWellnessCheck $check, array $passengerIds, string $status, ?string $note, bool $asStaff): ChatWellnessCheck
    {
        if ($check->isClosed()) {
            throw new \Exception('ปิดเช็คอาการนี้ไปแล้ว ถ้าไม่สบายทักทีมงานได้เลยครับ');
        }
        if (! in_array($status, ChatWellnessCheck::STATUSES, true)) {
            throw new \Exception('ไม่รู้จักคำตอบนี้');
        }

        $roster = $this->rosterOf($check);
        $allowed = $asStaff
            ? $roster->pluck('passenger_id')
            : $roster->where('user_id', $user->id)->pluck('passenger_id');
        $ids = collect($passengerIds)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn ($id) => $allowed->contains($id))
            ->values();

        if ($ids->isEmpty()) {
            throw new \Exception($asStaff ? 'ไม่พบผู้เดินทางในรอบนี้' : 'ตอบได้เฉพาะตัวเองและคนในกลุ่มที่คุณจองให้');
        }

        // สบายดีไม่ต้องมีหมายเหตุ — เคลียร์ทิ้ง กันข้อความเก่า "ปวดหัว" ค้างอยู่ทั้งที่หายแล้ว
        $note = $status === ChatWellnessCheck::STATUS_OK ? null : (trim((string) $note) ?: null);

        $becameUnwell = collect();
        DB::transaction(function () use ($check, $ids, $status, $note, $user, &$becameUnwell) {
            foreach ($ids as $id) {
                $answer = ChatWellnessAnswer::firstOrNew(['check_id' => $check->id, 'passenger_id' => $id]);
                $wasUnwell = $answer->exists && $answer->status === ChatWellnessCheck::STATUS_UNWELL;

                $answer->fill([
                    'status' => $status,
                    'note' => $note,
                    'answered_by_id' => $user->id,
                    // อาการเปลี่ยน = ทีมงานต้องดูใหม่
                    'handled_at' => $answer->exists && $answer->status === $status ? $answer->handled_at : null,
                    'handled_by_id' => $answer->exists && $answer->status === $status ? $answer->handled_by_id : null,
                ]);
                $answer->save();

                if ($status === ChatWellnessCheck::STATUS_UNWELL && ! $wasUnwell) {
                    $becameUnwell->push($id);
                }
            }
        });

        if ($becameUnwell->isNotEmpty()) {
            $people = $roster->whereIn('passenger_id', $becameUnwell->all());
            $title = '🤕 '.$people->pluck('name')->join(', ').' บอกว่าไม่ค่อยไหว';
            $body = ($note ? "\"{$note}\" — " : '').'เปิดแชทดูรายละเอียดแล้วกด "ไปดูแลแล้ว"';

            foreach ($this->staffIds($check->schedule) as $staffId) {
                $this->send($staffId, [$title, $body], $this->pushData($check, 'wellness_unwell'), channel: 'important_updates');
            }
        }

        return $this->broadcastUpdate($check);
    }

    /** ทีมงานไปดูแลคนนี้แล้ว — คนดูแลได้รู้ว่ามีคนมาหา */
    public function markHandled(User $staff, ChatWellnessCheck $check, int $passengerId): ChatWellnessCheck
    {
        $answer = ChatWellnessAnswer::where('check_id', $check->id)->where('passenger_id', $passengerId)->first();
        if (! $answer || $answer->status === ChatWellnessCheck::STATUS_OK) {
            throw new \Exception('คนนี้ไม่ได้บอกว่าเหนื่อยหรือไม่สบาย');
        }
        if ($answer->handled_at !== null) {
            return $this->broadcastUpdate($check);
        }

        $answer->update(['handled_at' => now(), 'handled_by_id' => $staff->id]);

        $person = $this->rosterOf($check)->firstWhere('passenger_id', $passengerId);
        if ($person && $person['user_id']) {
            $staffName = $staff->nickname ?: $staff->name;
            $this->send((int) $person['user_id'], [
                "❤️ ทีมงานกำลังไปดูแล{$person['name']}",
                "{$staffName} รับทราบแล้ว รออยู่ตรงนั้นก่อนนะครับ ไม่ต้องรีบ",
            ], $this->pushData($check, 'wellness_handled'));
        }

        return $this->broadcastUpdate($check);
    }

    public function close(ChatWellnessCheck $check): ChatWellnessCheck
    {
        $claimed = ChatWellnessCheck::whereKey($check->id)->whereNull('closed_at')->update(['closed_at' => now()]);
        if ($claimed === 0) {
            return $check->fresh('answers');
        }

        $fresh = $this->broadcastUpdate($check);
        $p = $this->present($fresh);
        $this->chatService->postSystem(
            $fresh->schedule,
            "❤️ ปิดเช็คอาการแล้ว — ตอบ {$p['answered_count']}/{$p['total']} คน ขอบคุณที่บอกกันนะครับ ไม่ไหวเมื่อไหร่ทักทีมงานได้ตลอด",
        );

        return $fresh;
    }

    /**
     * งานตามเวลา: ครบ REMIND_AFTER_MINUTES ถามซ้ำคนที่ยังเงียบ (ครั้งเดียว) และบอก
     * ทีมงานว่าใครยังไม่ตอบ / ใครไม่ไหว, ครบ AUTO_CLOSE_AFTER_HOURS ปิดเอง
     */
    public function settleDue(): int
    {
        $handled = 0;

        ChatWellnessCheck::whereNull('closed_at')
            ->whereNotNull('message_id')
            ->where('created_at', '<=', now()->subMinutes(ChatWellnessCheck::REMIND_AFTER_MINUTES))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(function (ChatWellnessCheck $check) use (&$handled) {
                try {
                    if ($check->created_at->lte(now()->subHours(ChatWellnessCheck::AUTO_CLOSE_AFTER_HOURS))) {
                        $this->close($check);
                    } else {
                        $this->remind($check);
                    }
                    $handled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: เช็คอาการทำงานไม่สำเร็จ', ['check_id' => $check->id, 'error' => $e->getMessage()]);
                }
            });

        return $handled;
    }

    /**
     * payload — ส่วนสาธารณะ (ทุกคน), [viewerId] เพิ่มคำตอบของคนที่บัญชีนั้นดูแล,
     * [withStaffDetail] เพิ่มรายชื่อพร้อมคำตอบ หมายเหตุ เบอร์ สำหรับทีมงาน
     *
     * @return array<string, mixed>
     */
    public function present(ChatWellnessCheck $check, ?int $viewerId = null, bool $withStaffDetail = false): array
    {
        $check->loadMissing('answers');
        $answers = $check->answers->keyBy('passenger_id');
        $roster = $this->rosterOf($check);
        $answered = $roster->filter(fn ($p) => $answers->has($p['passenger_id']));

        $row = function (array $p) use ($answers, $withStaffDetail) {
            $a = $answers->get($p['passenger_id']);

            return [
                'id' => $p['passenger_id'],
                'name' => $p['name'],
                'status' => $a?->status,
                'note' => $a?->note,
                'handled' => $a?->handled_at !== null,
                ...($withStaffDetail ? [
                    'phone' => $p['phone'],
                    'answered_at' => $a?->updated_at?->toISOString(),
                ] : []),
            ];
        };

        $payload = [
            'id' => $check->id,
            'label' => $check->label,
            'is_closed' => $check->isClosed(),
            'total' => $roster->count(),
            'answered_count' => $answered->count(),
            'created_at' => $check->created_at?->toISOString(),
        ];

        if ($viewerId !== null) {
            $payload['mine'] = $roster->where('user_id', $viewerId)->map($row)->values()->all();
        }

        if ($withStaffDetail) {
            $rows = $roster->map($row)->values();
            $order = [ChatWellnessCheck::STATUS_UNWELL => 0, ChatWellnessCheck::STATUS_TIRED => 1, null => 2, ChatWellnessCheck::STATUS_OK => 3];
            $payload['detail'] = [
                'counts' => [
                    'ok' => $rows->where('status', ChatWellnessCheck::STATUS_OK)->count(),
                    'tired' => $rows->where('status', ChatWellnessCheck::STATUS_TIRED)->count(),
                    'unwell' => $rows->where('status', ChatWellnessCheck::STATUS_UNWELL)->count(),
                    'none' => $rows->whereNull('status')->count(),
                ],
                // ไม่ไหวขึ้นก่อน แล้วเหนื่อย แล้วคนที่ยังไม่ตอบ สบายอยู่ท้ายสุด
                'passengers' => $rows->sortBy(fn ($r) => $order[$r['status']] ?? 2)->values()->all(),
            ];
        }

        return $payload;
    }

    // ── ภายใน ───────────────────────────────────────────────────────────────

    private function remind(ChatWellnessCheck $check): void
    {
        $claimed = ChatWellnessCheck::whereKey($check->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);
        if ($claimed === 0) {
            return;
        }

        $check->load('answers');
        $answeredIds = $check->answers->pluck('passenger_id')->map(fn ($id) => (int) $id);
        $missing = $this->rosterOf($check)->reject(fn ($p) => $answeredIds->contains($p['passenger_id']))->values();
        $unwell = $check->answers->where('status', ChatWellnessCheck::STATUS_UNWELL)->whereNull('handled_at')->count();

        if ($missing->isEmpty() && $unwell === 0) {
            return;
        }

        $tripTitle = $check->schedule->trip?->title ?? 'ทริปของคุณ';
        $data = $this->pushData($check, 'wellness_remind');

        foreach ($missing->pluck('user_id')->filter()->unique() as $userId) {
            $names = $missing->where('user_id', $userId)->pluck('name');
            $who = $names->count() > 1 ? ' ('.$names->join(', ').')' : '';
            $this->send((int) $userId, [
                "❤️ ยังไหวไหมครับ?{$who}",
                "ทีมงานถามอาการอยู่ กดตอบในแชทหน่อยนะครับ เห็นแค่ทีมงาน · {$tripTitle}",
            ], $data);
        }

        $names = $missing->pluck('name');
        $parts = array_filter([
            $missing->isNotEmpty() ? 'ยังไม่ตอบ: '.$names->take(8)->join(', ').($names->count() > 8 ? ' และอีก '.($names->count() - 8).' คน' : '') : null,
            $unwell > 0 ? "ไม่ค่อยไหวยังไม่มีคนไปดู {$unwell} คน" : null,
        ]);
        foreach ($this->staffIds($check->schedule) as $staffId) {
            $this->send($staffId, ['❤️ เช็คอาการ: ยังต้องตาม', implode(' · ', $parts)], $data);
        }
    }

    /** @var array<int, Collection<int, array<string, mixed>>> */
    private array $rosterCache = [];

    /** @return Collection<int, array<string, mixed>> */
    private function rosterOf(ChatWellnessCheck $check): Collection
    {
        return $this->rosterCache[$check->schedule_id] ??= $this->roster->travelling($check->schedule);
    }

    /** @return Collection<int, int> */
    private function staffIds(TripSchedule $schedule): Collection
    {
        return $schedule->activeStaff()->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    /** @return array<string, string> */
    private function pushData(ChatWellnessCheck $check, string $type): array
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
    private function send(int $userId, array $text, array $data, string $channel = SendChatPushJob::ANDROID_CHANNEL): void
    {
        try {
            $this->fcm->sendToUser($userId, $text[0], $text[1], $data, ['android_channel' => $channel]);
        } catch (\Throwable $e) {
            Log::warning('ChatWellnessService: ส่ง push ไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    private function broadcastUpdate(ChatWellnessCheck $check): ChatWellnessCheck
    {
        $fresh = $check->fresh('answers');

        broadcast(new ChatWellnessUpdated(
            (int) $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }
}
