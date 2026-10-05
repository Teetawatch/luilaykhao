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
use Illuminate\Support\Facades\Log;

/**
 * โพลในห้องแชททริป — ใช้ตัดสินใจร่วมกัน ("แวะกินข้าวจุดไหน", "ออกกี่โมงดี")
 * แทนการไล่นับมือในแชท
 *
 * โพลหนึ่งใบผูกกับข้อความหนึ่งใบในห้อง เพื่อให้ไหลตามลำดับเวลา ตอบกลับและ
 * ปักหมุดได้เหมือนข้อความปกติ
 *
 * มีสองแบบ: โพลทั่วไป (ดูผลไปเรื่อย ๆ) กับ "โหวต" (KIND_VOTE) ที่ไว้ตัดสิน
 * ตอนเสียงแตก — เลือกข้อเดียว มีเวลาจำกัดเสมอ ปิดเองเมื่อลูกทริปโหวตครบหรือหมดเวลา
 * แล้วประกาศผลเสียงข้างมากเข้าห้อง จะได้ไม่ต้องมีใครมานั่งนับแล้วสรุปเอง
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
        string $kind = ChatPoll::KIND_POLL,
        ?int $durationMinutes = null,
    ): ChatPoll {
        $isVote = $kind === ChatPoll::KIND_VOTE;

        // โหวตไม่ได้พิมพ์ตัวเลือกมา = ถามแบบ เห็นด้วย / ไม่เห็นด้วย
        if ($isVote && collect($options)->map(fn ($o) => trim((string) $o))->filter()->isEmpty()) {
            $options = ChatPoll::VOTE_DEFAULT_OPTIONS;
        }

        $labels = collect($options)
            ->map(fn ($o) => trim((string) $o))
            ->filter()
            ->unique()
            ->take($isVote ? ChatPoll::VOTE_MAX_OPTIONS : ChatPoll::MAX_OPTIONS)
            ->values();

        if ($labels->count() < ChatPoll::MIN_OPTIONS) {
            throw new \Exception('ต้องมีตัวเลือกอย่างน้อย '.ChatPoll::MIN_OPTIONS.' ข้อที่ไม่ซ้ำกัน');
        }

        if ($isVote) {
            // ตัดสินเสียงข้างมากต้องหนึ่งคนหนึ่งเสียง และต้องมีเส้นตายเสมอ
            $allowMultiple = false;
            $minutes = $durationMinutes
                ?? ($durationHours ? $durationHours * 60 : ChatPoll::VOTE_DEFAULT_MINUTES);
            $closesAt = now()->addMinutes(min(max($minutes, 1), ChatPoll::VOTE_MAX_MINUTES));
        } elseif ($durationMinutes) {
            $closesAt = now()->addMinutes($durationMinutes);
        } else {
            $closesAt = $durationHours ? now()->addHours($durationHours) : null;
        }

        $poll = DB::transaction(function () use ($user, $schedule, $question, $labels, $allowMultiple, $closesAt, $isVote) {
            $poll = ChatPoll::create([
                'schedule_id' => $schedule->id,
                'created_by_id' => $user->id,
                'question' => $question,
                'kind' => $isVote ? ChatPoll::KIND_VOTE : ChatPoll::KIND_POLL,
                'allow_multiple' => $allowMultiple,
                'closes_at' => $closesAt,
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
                'body' => ($isVote ? '🗳️ โหวต: ' : '📊 ').$question,
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

        // โหวตตัดสิน: ลูกทริปโหวตครบทุกคนแล้วไม่ต้องรอหมดเวลา ปิดแล้วประกาศผลเลย
        if ($poll->isVote() && $chosen->isNotEmpty() && $this->everyoneVoted($poll)) {
            return $this->close($poll);
        }

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

        $fresh = $this->broadcastUpdate($poll);
        $this->announceResult($fresh);

        return $fresh;
    }

    /**
     * โหวตที่หมดเวลาแต่ยังไม่ได้ประกาศผล — เรียกจาก SettleChatPollsJob ทุกนาที
     * (การ์ดในแอปต้องพลิกเป็น "ปิดแล้ว" แบบเรียลไทม์ด้วย จึงกระจายอัปเดตไปพร้อมกัน)
     */
    public function settleDue(): int
    {
        $settled = 0;

        ChatPoll::where('kind', ChatPoll::KIND_VOTE)
            ->whereNull('announced_at')
            ->whereNotNull('message_id')
            ->where(fn ($q) => $q->whereNotNull('closed_at')->orWhere('closes_at', '<=', now()))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (ChatPoll $poll) use (&$settled) {
                try {
                    $this->announceResult($this->broadcastUpdate($poll));
                    $settled++;
                } catch (\Throwable $e) {
                    Log::warning('SettleChatPolls: ประกาศผลโหวตไม่สำเร็จ', [
                        'poll_id' => $poll->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $settled;
    }

    /**
     * ผลโหวตเสียงข้างมาก
     *
     * @return array{status: string, winner_label: ?string, winner_votes: int, runner_up_votes: int, total_votes: int, tied_labels: array<int, string>}
     */
    public function result(ChatPoll $poll): array
    {
        $poll->loadMissing(['options', 'votes']);

        $counts = $poll->options->map(fn (ChatPollOption $o) => [
            'label' => $o->label,
            'votes' => $poll->votes->where('option_id', $o->id)->count(),
        ])->sortByDesc('votes')->values();

        $total = (int) $counts->sum('votes');
        $top = (int) ($counts->first()['votes'] ?? 0);
        $leaders = $counts->where('votes', $top)->values();

        $status = match (true) {
            $total === 0 => 'no_votes',
            $leaders->count() > 1 => 'tie',
            default => 'winner',
        };

        return [
            'status' => $status,
            'winner_label' => $status === 'winner' ? $leaders->first()['label'] : null,
            'winner_votes' => $top,
            'runner_up_votes' => (int) ($counts->get(1)['votes'] ?? 0),
            'total_votes' => $total,
            'tied_labels' => $status === 'tie' ? $leaders->pluck('label')->all() : [],
        ];
    }

    /**
     * ลูกทริปในห้องโหวตครบทุกคนแล้วหรือยัง — นับเฉพาะลูกค้า/เพื่อนร่วมจอง
     * (สตาฟโหวตได้ แต่ไม่ต้องรอสตาฟ เพราะสตาฟคือคนที่รอฟังผลอยู่)
     */
    private function everyoneVoted(ChatPoll $poll): bool
    {
        $schedule = $poll->schedule;
        if (! $schedule) {
            return false;
        }

        $travellerIds = $this->chatService->members($schedule)
            ->filter(fn ($m) => $m['role'] === 'customer')
            ->map(fn ($m) => (int) $m['user']->id);

        if ($travellerIds->isEmpty()) {
            return false;
        }

        $voterIds = ChatPollVote::where('poll_id', $poll->id)->pluck('user_id')->map(fn ($id) => (int) $id);

        return $travellerIds->diff($voterIds)->isEmpty();
    }

    /**
     * ประกาศผลโหวตเข้าห้องครั้งเดียว — ทั้งปิดมือ โหวตครบ และหมดเวลา วิ่งมาที่นี่
     * จองสิทธิ์ด้วย announced_at ก่อนโพสต์ เพื่อไม่ให้สองทางประกาศซ้ำกัน
     */
    private function announceResult(ChatPoll $poll): void
    {
        if (! $poll->isVote() || ! $poll->isClosed() || $poll->announced_at !== null) {
            return;
        }

        $claimed = ChatPoll::whereKey($poll->id)
            ->whereNull('announced_at')
            ->update(['announced_at' => now()]);

        if ($claimed === 0 || ! $poll->schedule) {
            return;
        }

        $this->chatService->postSystem(
            $poll->schedule,
            $this->resultBody($poll),
            "vote_result:{$poll->id}",
        );
    }

    private function resultBody(ChatPoll $poll): string
    {
        $r = $this->result($poll);
        $question = "“{$poll->question}”";

        return match ($r['status']) {
            'no_votes' => "🗳️ ปิดโหวต {$question} แล้ว แต่ยังไม่มีใครโหวตเลยครับ",
            'tie' => "🗳️ ผลโหวต {$question} — เสมอกัน "
                .collect($r['tied_labels'])->map(fn ($l) => "“{$l}”")->join(', ', ' กับ ')
                ." ได้ {$r['winner_votes']} เสียงเท่ากัน ขอให้ทีมงานช่วยตัดสินนะครับ 🙏",
            default => "🗳️ ผลโหวต {$question} — “{$r['winner_label']}” ชนะ "
                .($poll->options->count() === 2
                    ? "{$r['winner_votes']} ต่อ {$r['runner_up_votes']} เสียง"
                    : "ด้วย {$r['winner_votes']} จาก {$r['total_votes']} เสียง")
                .' 🎉',
        };
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
            'kind' => $poll->kind ?: ChatPoll::KIND_POLL,
            'allow_multiple' => (bool) $poll->allow_multiple,
            'is_closed' => $poll->isClosed(),
            'closes_at' => $poll->closes_at?->toISOString(),
            'created_by_id' => $poll->created_by_id ? (int) $poll->created_by_id : null,
            // จำนวน "คน" ที่โหวต ไม่ใช่จำนวนคะแนน (โพลหลายข้อคนเดียวกดได้หลายข้อ)
            'voter_count' => $poll->votes->pluck('user_id')->unique()->count(),
            'my_option_ids' => $myVotes->values()->all(),
            // ผลตัดสินของโหวตที่ปิดแล้ว — การ์ดใช้ขึ้นแถบ "ผลโหวต" ตรงกับข้อความประกาศ
            'result' => $poll->isVote() && $poll->isClosed() ? $this->result($poll) : null,
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
