<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Events\ChatPollUpdated;
use App\Models\ChatMessage;
use App\Models\ChatPoll;
use App\Models\ChatPollOption;
use App\Models\ChatPollVote;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * โพลในห้องแชททริป — ใช้ตัดสินใจร่วมกัน ("แวะกินข้าวจุดไหน", "ออกกี่โมงดี")
 * แทนการไล่นับมือในแชท
 *
 * โพลหนึ่งใบผูกกับข้อความหนึ่งใบในห้อง เพื่อให้ไหลตามลำดับเวลา ตอบกลับและ
 * ปักหมุดได้เหมือนข้อความปกติ
 */
class ChatPollService
{
    public function __construct(private ChatService $chatService) {}

    /**
     * สร้างโพลพร้อมข้อความในห้อง แล้วกระจายเรียลไทม์
     *
     * @param  array<int, string>  $options
     */
    public function create(
        User $user,
        TripSchedule $schedule,
        string $question,
        array $options,
        bool $allowMultiple = false,
        ?int $durationHours = null,
    ): ChatPoll {
        $labels = collect($options)
            ->map(fn ($o) => trim((string) $o))
            ->filter()
            ->unique()
            ->take(ChatPoll::MAX_OPTIONS)
            ->values();

        if ($labels->count() < ChatPoll::MIN_OPTIONS) {
            throw new \Exception('ต้องมีตัวเลือกอย่างน้อย '.ChatPoll::MIN_OPTIONS.' ข้อที่ไม่ซ้ำกัน');
        }

        $poll = DB::transaction(function () use ($user, $schedule, $question, $labels, $allowMultiple, $durationHours) {
            $poll = ChatPoll::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $user->id,
                'question' => $question,
                'allow_multiple' => $allowMultiple,
                'closes_at' => $durationHours ? now()->addHours($durationHours) : null,
            ]);

            foreach ($labels as $i => $label) {
                ChatPollOption::create([
                    'poll_id' => $poll->id,
                    'label' => $label,
                    'sort_order' => $i,
                ]);
            }

            // ข้อความที่ห่อโพลไว้ — body เก็บคำถามด้วย เพื่อให้ตัวอย่างข้อความล่าสุด
            // ในรายการแชทและ push อ่านรู้เรื่องโดยไม่ต้องรู้จักโพล
            $message = ChatMessage::create([
                'schedule_id' => $schedule->id,
                'user_id' => $user->id,
                'sender_role' => $this->chatService->senderRole($user, $schedule),
                'body' => "📊 {$question}",
            ]);

            $poll->update(['message_id' => $message->id]);

            return $poll;
        });

        $message = $poll->message()->with(['user', 'replyTo.user', 'reactions', 'poll.options', 'poll.votes'])->first();
        if ($message) {
            broadcast(new ChatMessageSent($message))->toOthers();
        }

        return $poll->fresh(['options', 'votes']);
    }

    /**
     * ลงคะแนน — โพลเลือกข้อเดียวจะแทนที่คะแนนเดิม, โพลหลายข้อจะเก็บตามที่ส่งมา
     * ส่งอาร์เรย์ว่างมาได้ = ถอนโหวตทั้งหมด
     *
     * @param  array<int, int>  $optionIds
     */
    public function vote(User $user, ChatPoll $poll, array $optionIds): ChatPoll
    {
        if ($poll->isClosed()) {
            throw new \Exception('โพลนี้ปิดโหวตแล้ว');
        }

        $validIds = $poll->options()->pluck('id')->map(fn ($id) => (int) $id);
        $chosen = collect($optionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $validIds->contains($id))
            ->unique()
            ->values();

        if (! $poll->allow_multiple) {
            $chosen = $chosen->take(1);
        }

        DB::transaction(function () use ($poll, $user, $chosen) {
            ChatPollVote::where('poll_id', $poll->id)
                ->where('user_id', $user->id)
                ->delete();

            foreach ($chosen as $optionId) {
                ChatPollVote::create([
                    'poll_id' => $poll->id,
                    'option_id' => $optionId,
                    'user_id' => $user->id,
                ]);
            }
        });

        return $this->broadcastUpdate($poll);
    }

    /**
     * ปิดโพล — คนสร้างหรือสตาฟ/แอดมินเท่านั้น (ตรวจสิทธิ์ที่ controller)
     */
    public function close(ChatPoll $poll): ChatPoll
    {
        if ($poll->closed_at === null) {
            $poll->update(['closed_at' => now()]);
        }

        return $this->broadcastUpdate($poll);
    }

    private function broadcastUpdate(ChatPoll $poll): ChatPoll
    {
        $fresh = $poll->fresh(['options', 'votes']);

        broadcast(new ChatPollUpdated(
            $fresh->schedule_id,
            (int) $fresh->message_id,
            $this->present($fresh),
        ));

        return $fresh;
    }

    /**
     * payload ของโพลสำหรับ API / broadcast
     *
     * ผลโหวตเปิดให้ทุกคนเห็นเสมอ (ทริปกลุ่มต้องรู้ว่าใครเลือกอะไรถึงจะนัดกันได้)
     * — voter_ids ใช้โชว์รูปคนที่เลือกในแต่ละข้อ
     *
     * @return array<string, mixed>
     */
    public function present(ChatPoll $poll, ?int $currentUserId = null): array
    {
        $poll->loadMissing(['options', 'votes']);

        $votesByOption = $poll->votes->groupBy('option_id');
        $myVotes = $currentUserId === null
            ? collect()
            : $poll->votes->where('user_id', $currentUserId)->pluck('option_id')->map(fn ($id) => (int) $id);

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'allow_multiple' => (bool) $poll->allow_multiple,
            'is_closed' => $poll->isClosed(),
            'closes_at' => $poll->closes_at?->toISOString(),
            'created_by_id' => $poll->created_by_id ? (int) $poll->created_by_id : null,
            // จำนวน "คน" ที่โหวต ไม่ใช่จำนวนคะแนน (โพลหลายข้อคนเดียวกดได้หลายข้อ)
            'voter_count' => $poll->votes->pluck('user_id')->unique()->count(),
            'my_option_ids' => $myVotes->values()->all(),
            'options' => $poll->options->map(function (ChatPollOption $o) use ($votesByOption, $myVotes) {
                $votes = $votesByOption->get($o->id, collect());

                return [
                    'id' => $o->id,
                    'label' => $o->label,
                    'vote_count' => $votes->count(),
                    'voter_ids' => $votes->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all(),
                    'voted_by_me' => $myVotes->contains($o->id),
                ];
            })->values()->all(),
        ];
    }
}
