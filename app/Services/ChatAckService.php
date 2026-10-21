<?php

namespace App\Services;

use App\Events\ChatAckUpdated;
use App\Jobs\SendChatAckPushJob;
use App\Models\ChatMessage;
use App\Models\ChatMessageAck;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "รับทราบ" ใต้ประกาศของทีมงาน
 *
 * นับเฉพาะลูกทริป (คนที่จอง + เพื่อนที่ร่วมใบจอง) — ทีมงานเป็นคนประกาศ ไม่ต้องกด
 * คนที่ออกจากรอบไปแล้ว (ยกเลิกการจอง) หลุดจากทั้งตัวตั้งและตัวหาร เลขจึงไม่เกิน 100%
 */
class ChatAckService
{
    /** สะกิดคนที่ยังไม่กดได้ห่างกันอย่างน้อยเท่านี้ */
    public const NUDGE_COOLDOWN_MINUTES = 15;

    public function __construct(private ChatService $chat) {}

    /**
     * ลูกทริปในห้องนี้ (ไม่รวมทีมงาน) — คนที่ควรกดรับทราบ
     *
     * @return Collection<int, int>
     */
    public function audienceIds(TripSchedule $schedule): Collection
    {
        $staffIds = $schedule->staff()->pluck('users.id')->map(fn ($id) => (int) $id)->all();

        return $this->chat->pushRecipientIds($schedule)
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $staffIds, true))
            ->values();
    }

    /**
     * ทีมงานขอให้ทุกคนกดรับทราบ — ใช้กับข้อความที่มีอยู่แล้วได้ (รวมข้อความระบบ)
     * ขอซ้ำ = ไม่ทำอะไร (ไม่ยิง push ซ้ำ ใช้ "สะกิด" แทน)
     *
     * @param  bool  $notify  แจ้งเตือนลูกทริป — ปิดเมื่อข้อความเพิ่งส่งและมี push ของมันเองแล้ว
     */
    public function request(User $by, ChatMessage $message, bool $notify = true): ChatMessage
    {
        if ($message->is_deleted || $message->hidden_at !== null) {
            throw new \Exception('ข้อความนี้ถูกลบแล้ว');
        }

        if ($message->ack_requested_at !== null) {
            return $message;
        }

        $message->forceFill([
            'ack_requested_at' => now(),
            'ack_requested_by_id' => $by->id,
            'ack_nudged_at' => null,
        ])->save();

        $this->broadcast($message);

        if ($notify) {
            SendChatAckPushJob::dispatch($message->id, false);
        }

        return $message;
    }

    /** ยกเลิกการขอ — ล้างคนที่กดไปแล้วด้วย ขอใหม่ทีหลังจะได้เริ่มนับจากศูนย์ */
    public function cancel(ChatMessage $message): void
    {
        DB::transaction(function () use ($message) {
            ChatMessageAck::where('message_id', $message->id)->delete();
            $message->forceFill([
                'ack_requested_at' => null,
                'ack_requested_by_id' => null,
                'ack_nudged_at' => null,
            ])->save();
        });

        broadcast(new ChatAckUpdated($message->schedule_id, $message->id, null))->toOthers();
    }

    /** ลูกทริปกดรับทราบ — กดซ้ำได้ไม่เกิดอะไร */
    public function ack(User $user, ChatMessage $message): ChatMessage
    {
        if ($message->ack_requested_at === null || $message->is_deleted) {
            throw new \Exception('ประกาศนี้ไม่ได้ขอให้กดรับทราบแล้ว');
        }

        $schedule = $message->schedule;

        if (! $this->audienceIds($schedule)->contains((int) $user->id)) {
            throw new \Exception('ทีมงานไม่ต้องกดรับทราบประกาศของห้องนี้');
        }

        ChatMessageAck::firstOrCreate(['message_id' => $message->id, 'user_id' => $user->id]);
        $message->unsetRelation('acks');

        $this->broadcast($message);

        return $message;
    }

    /**
     * ตัวนับของข้อความนี้ (null = ไม่ได้ขอรับทราบ)
     * $viewerId = null (เช่นใน broadcast) → ไม่มี acked_by_me ให้แอปคงค่าเดิมไว้
     *
     * @return array<string, mixed>|null
     */
    public function present(ChatMessage $message, ?int $viewerId = null): ?array
    {
        if ($message->ack_requested_at === null || $message->is_deleted) {
            return null;
        }

        $audience = $this->audienceIds($message->schedule);
        $ackIds = ($message->relationLoaded('acks') ? $message->acks : $message->acks()->get())
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
        $requestedBy = $message->ackRequestedBy;

        return [
            'requested_at' => $message->ack_requested_at->toISOString(),
            'requested_by' => $requestedBy ? ($requestedBy->nickname ?: $requestedBy->name) : null,
            'count' => $ackIds->intersect($audience)->count(),
            'total' => $audience->count(),
            'acked_by_me' => $viewerId === null ? null : $ackIds->contains($viewerId),
            // ตอนนี้สะกิดได้ไหม (ทีมงานใช้ปิดปุ่ม) — null = ยังไม่เคยสะกิด
            'nudged_at' => $message->ack_nudged_at?->toISOString(),
        ];
    }

    /**
     * รายชื่อสำหรับทีมงาน: ใครกดแล้ว (พร้อมเวลา) / ใครยังไม่กด
     *
     * @return array{acked: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>}
     */
    public function detail(ChatMessage $message): array
    {
        $audience = $this->audienceIds($message->schedule);
        $acks = ChatMessageAck::where('message_id', $message->id)
            ->whereIn('user_id', $audience)
            ->get()
            ->keyBy('user_id');
        $users = User::whereIn('id', $audience)->get(['id', 'name', 'nickname', 'avatar'])->keyBy('id');

        $row = fn (User $u) => [
            'id' => $u->id,
            'name' => $u->nickname ?: $u->name,
            'avatar_url' => $u->avatar_url,
        ];

        $acked = $acks
            ->sortBy('created_at')
            ->map(fn (ChatMessageAck $a) => $users->has($a->user_id)
                ? [...$row($users[$a->user_id]), 'acked_at' => $a->created_at?->toISOString()]
                : null)
            ->filter()
            ->values()
            ->all();

        $pending = $users
            ->reject(fn (User $u) => $acks->has($u->id))
            ->sortBy(fn (User $u) => $u->nickname ?: $u->name)
            ->map($row)
            ->values()
            ->all();

        return ['acked' => $acked, 'pending' => $pending];
    }

    /**
     * สะกิดเฉพาะคนที่ยังไม่กด — คืนจำนวนคนที่สะกิด
     */
    public function nudge(ChatMessage $message): int
    {
        if ($message->ack_requested_at === null || $message->is_deleted) {
            throw new \Exception('ประกาศนี้ไม่ได้ขอให้กดรับทราบแล้ว');
        }

        if ($message->ack_nudged_at !== null
            && $message->ack_nudged_at->gt(now()->subMinutes(self::NUDGE_COOLDOWN_MINUTES))) {
            $wait = max(1, (int) ceil(now()->diffInMinutes(
                $message->ack_nudged_at->copy()->addMinutes(self::NUDGE_COOLDOWN_MINUTES),
            )));

            throw new \Exception("เพิ่งสะกิดไป รออีก {$wait} นาทีค่อยสะกิดซ้ำนะครับ");
        }

        $pending = $this->pendingUserIds($message);

        if ($pending->isEmpty()) {
            throw new \Exception('ทุกคนกดรับทราบครบแล้ว');
        }

        $message->forceFill(['ack_nudged_at' => now()])->save();
        $this->broadcast($message);
        SendChatAckPushJob::dispatch($message->id, true);

        return $pending->count();
    }

    /**
     * ลูกทริปที่ยังไม่กดรับทราบข้อความนี้
     *
     * @return Collection<int, int>
     */
    public function pendingUserIds(ChatMessage $message): Collection
    {
        $acked = ChatMessageAck::where('message_id', $message->id)->pluck('user_id')->map(fn ($id) => (int) $id);

        return $this->audienceIds($message->schedule)->diff($acked)->values();
    }

    /**
     * ประกาศที่รอผู้ใช้คนนี้กดรับทราบในห้องนี้ (ใหม่สุดก่อน)
     *
     * @return Collection<int, ChatMessage>
     */
    public function pendingFor(User $user, TripSchedule $schedule): Collection
    {
        if (! $this->audienceIds($schedule)->contains((int) $user->id)) {
            return collect();
        }

        return ChatMessage::where('schedule_id', $schedule->id)
            ->whereNotNull('ack_requested_at')
            ->where('is_deleted', false)
            ->whereNull('hidden_at')
            ->whereDoesntHave('acks', fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')
            ->get();
    }

    private function broadcast(ChatMessage $message): void
    {
        broadcast(new ChatAckUpdated(
            (int) $message->schedule_id,
            (int) $message->id,
            $this->present($message),
        ))->toOthers();
    }
}
