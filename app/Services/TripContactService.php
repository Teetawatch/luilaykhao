<?php

namespace App\Services;

use App\Models\TripContactExchange;
use App\Models\TripSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * แลกคอนแทค (LINE / IG / Facebook / TikTok) กับเพื่อนร่วมทริป — ต้องยินยอมทั้งสองฝ่าย
 *
 * - คอนแทคเป็นของบัญชี (users.social_handles) ตั้งครั้งเดียวใช้ทุกทริป
 * - เห็นคอนแทคของอีกฝ่ายได้เฉพาะเมื่อคำขอถูกตอบรับแล้ว และเห็นค่าปัจจุบันเสมอ
 *   (ลบคอนแทคออกจากโปรไฟล์ = เพื่อนเห็นว่าง ไม่มีสำเนาค้างไว้ที่ไหน)
 * - ขอได้กับคนที่ไปทริปเดียวกันจริง (รวมทีมงาน) ตั้งแต่วันออกเดินทางถึง WINDOW_DAYS
 *   หลังกลับ — อยู่ต่อหลังห้องแชทถูกลบ ดูย้อนหลังได้ในโปรไฟล์ตลอด
 * - ถูกปฏิเสธ = ผู้ขอยังเห็นว่า "ส่งคำขอแล้ว" (ไม่บอกว่าโดนปฏิเสธ) และขอซ้ำไม่ได้
 * - คนที่บล็อกกันมองไม่เห็นกันในรายการนี้
 */
class TripContactService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** ขอแลกได้ถึงกี่วันหลังวันกลับ */
    public const WINDOW_DAYS = 30;

    public const HANDLE_KEYS = ['line', 'instagram', 'facebook', 'tiktok'];

    public function __construct(
        private TripRosterService $roster,
        private ModerationService $moderation,
        private FcmService $fcm,
    ) {}

    public function windowOpen(TripSchedule $schedule): bool
    {
        if (! $schedule->departure_date || $schedule->status === 'cancelled') {
            return false;
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $start = $schedule->departure_date->toDateString();
        $end = CarbonImmutable::parse(($schedule->return_date ?? $schedule->departure_date)->toDateString())
            ->addDays(self::WINDOW_DAYS)
            ->toDateString();

        return $today >= $start && $today <= $end;
    }

    /**
     * คนในทริปนี้ (ลูกทริปที่ไปจริง + ทีมงาน)
     *
     * @return Collection<int, int>
     */
    public function participantIds(TripSchedule $schedule): Collection
    {
        return $this->roster->userIds($schedule)
            ->merge($schedule->staff()->pluck('users.id')->map(fn ($id) => (int) $id))
            ->unique()
            ->values();
    }

    public function isParticipant(User $user, TripSchedule $schedule): bool
    {
        return $this->participantIds($schedule)->contains((int) $user->id);
    }

    /**
     * @return array<string, string>
     */
    public function handlesOf(User $user): array
    {
        return collect($user->social_handles ?? [])
            ->only(self::HANDLE_KEYS)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, ?string>  $handles
     * @return array<string, string>
     */
    public function saveHandles(User $user, array $handles): array
    {
        $clean = collect(self::HANDLE_KEYS)
            ->mapWithKeys(fn ($k) => [$k => $this->normalizeHandle($k, $handles[$k] ?? null)])
            ->filter()
            ->all();

        $user->forceFill(['social_handles' => $clean ?: null])->save();

        return $clean;
    }

    /**
     * รายชื่อในทริปพร้อมสถานะการแลกกับฉัน — คอนแทคของอีกฝ่ายมาเฉพาะเมื่อแลกกันแล้ว
     *
     * @return array<string, mixed>
     */
    public function board(User $me, TripSchedule $schedule): array
    {
        $staffIds = $schedule->staff()->pluck('users.id')->map(fn ($id) => (int) $id);
        $hidden = $this->moderation->hiddenAuthorIds($me);

        $ids = $this->participantIds($schedule)
            ->reject(fn ($id) => $id === (int) $me->id || in_array($id, $hidden, true))
            ->values();

        $users = User::whereIn('id', $ids)->get(['id', 'name', 'nickname', 'avatar', 'social_handles'])->keyBy('id');
        $exchanges = $this->exchangesWith($me, $schedule);

        $people = $ids
            ->filter(fn ($id) => $users->has($id))
            ->map(function (int $id) use ($users, $exchanges, $me, $staffIds) {
                $u = $users[$id];
                $status = $this->statusFor($me, $id, $exchanges);

                return [
                    'user_id' => $id,
                    'name' => $u->nickname ?: $u->name,
                    'avatar_url' => $u->avatar_url,
                    'role' => $staffIds->contains($id) ? 'staff' : 'customer',
                    'status' => $status,
                    'has_handles' => $this->handlesOf($u) !== [],
                    'handles' => $status === 'exchanged' ? $this->handlesOf($u) : null,
                ];
            })
            // คำขอที่รอฉันตอบขึ้นก่อน แล้วคนที่แลกกันแล้ว แล้วที่เหลือตามชื่อ
            ->sortBy(fn ($p) => [['incoming' => 0, 'exchanged' => 1, 'none' => 2, 'requested' => 3][$p['status']] ?? 4, $p['name']])
            ->values()
            ->all();

        return [
            'window_open' => $this->windowOpen($schedule),
            'my_handles' => $this->handlesOf($me),
            'people' => $people,
        ];
    }

    /** ส่งคำขอ — ถ้าอีกฝ่ายขอเรามาก่อนแล้ว ถือว่าตกลงกันทั้งคู่ทันที */
    public function request(User $me, TripSchedule $schedule, int $targetId): string
    {
        $this->guard($me, $schedule, $targetId);

        if ($this->handlesOf($me) === []) {
            throw new \Exception('ใส่คอนแทคของคุณก่อน อย่างน้อยหนึ่งช่อง เพื่อนจะได้เห็นเมื่อตอบรับ');
        }

        $reverse = TripContactExchange::where('schedule_id', $schedule->id)
            ->where('requester_id', $targetId)
            ->where('target_id', $me->id)
            ->first();

        if ($reverse && $reverse->status === TripContactExchange::PENDING) {
            $reverse->forceFill(['status' => TripContactExchange::ACCEPTED, 'responded_at' => now()])->save();
            $this->notify($targetId, $me, $schedule, accepted: true);

            return 'exchanged';
        }
        if ($reverse && $reverse->status === TripContactExchange::ACCEPTED) {
            return 'exchanged';
        }

        $exchange = TripContactExchange::firstOrCreate(
            ['schedule_id' => $schedule->id, 'requester_id' => $me->id, 'target_id' => $targetId],
            ['status' => TripContactExchange::PENDING],
        );

        if ($exchange->wasRecentlyCreated) {
            $this->notify($targetId, $me, $schedule, accepted: false);
        }

        return $exchange->status === TripContactExchange::ACCEPTED ? 'exchanged' : 'requested';
    }

    /** ตอบคำขอที่ $requesterId ส่งมา */
    public function respond(User $me, TripSchedule $schedule, int $requesterId, bool $accept): string
    {
        $exchange = TripContactExchange::where('schedule_id', $schedule->id)
            ->where('requester_id', $requesterId)
            ->where('target_id', $me->id)
            ->where('status', TripContactExchange::PENDING)
            ->first();

        if (! $exchange) {
            throw new \Exception('ไม่มีคำขอนี้แล้ว');
        }
        if ($accept && $this->handlesOf($me) === []) {
            throw new \Exception('ใส่คอนแทคของคุณก่อน อย่างน้อยหนึ่งช่อง แล้วค่อยตอบรับ');
        }

        $exchange->forceFill([
            'status' => $accept ? TripContactExchange::ACCEPTED : TripContactExchange::DECLINED,
            'responded_at' => now(),
        ])->save();

        if ($accept) {
            $this->notify($requesterId, $me, $schedule, accepted: true);
        }

        return $accept ? 'exchanged' : 'none';
    }

    /**
     * ทุกคนที่แลกคอนแทคกันแล้ว ทุกทริป (ใหม่สุดก่อน) — หน้า "คอนแทคเพื่อนร่วมทริป"
     *
     * @return array<int, array<string, mixed>>
     */
    public function myContacts(User $me): array
    {
        $hidden = $this->moderation->hiddenAuthorIds($me);

        return TripContactExchange::query()
            ->where('status', TripContactExchange::ACCEPTED)
            ->where(fn ($q) => $q->where('requester_id', $me->id)->orWhere('target_id', $me->id))
            ->with(['schedule.trip:id,title', 'requester:id,name,nickname,avatar,social_handles', 'target:id,name,nickname,avatar,social_handles'])
            ->orderByDesc('responded_at')
            ->get()
            ->map(function (TripContactExchange $e) use ($me) {
                $other = (int) $e->requester_id === (int) $me->id ? $e->target : $e->requester;

                return $other ? [
                    'user_id' => $other->id,
                    'name' => $other->nickname ?: $other->name,
                    'avatar_url' => $other->avatar_url,
                    'handles' => $this->handlesOf($other),
                    'schedule_id' => $e->schedule_id,
                    'trip_title' => $e->schedule?->trip?->title,
                    'departure_date' => $e->schedule?->departure_date?->toDateString(),
                ] : null;
            })
            ->filter(fn ($c) => $c !== null && ! in_array($c['user_id'], $hidden, true))
            // แลกกันหลายทริปเห็นคนเดิมครั้งเดียว (ทริปล่าสุด)
            ->unique('user_id')
            ->values()
            ->all();
    }

    /** คำขอที่รอฉันตอบในรอบนี้ — ป้ายบนปุ่มในห้องแชท */
    public function incomingCount(User $me, TripSchedule $schedule): int
    {
        $hidden = $this->moderation->hiddenAuthorIds($me);

        return TripContactExchange::where('schedule_id', $schedule->id)
            ->where('target_id', $me->id)
            ->where('status', TripContactExchange::PENDING)
            ->whereNotIn('requester_id', $hidden ?: [0])
            ->count();
    }

    private function guard(User $me, TripSchedule $schedule, int $targetId): void
    {
        if ($targetId === (int) $me->id) {
            throw new \Exception('แลกคอนแทคกับตัวเองไม่ได้');
        }
        if (! $this->windowOpen($schedule)) {
            throw new \Exception('แลกคอนแทคได้ตั้งแต่วันเดินทางถึง '.self::WINDOW_DAYS.' วันหลังจบทริป');
        }
        $participants = $this->participantIds($schedule);
        if (! $participants->contains((int) $me->id) || ! $participants->contains($targetId)) {
            throw new \Exception('แลกคอนแทคได้เฉพาะคนที่ไปทริปเดียวกัน');
        }
        if ($this->moderation->hasBlockBetween($me, $targetId)) {
            throw new \Exception('ส่งคำขอถึงคนนี้ไม่ได้');
        }
    }

    /**
     * @param  Collection<int, TripContactExchange>  $exchanges
     */
    private function statusFor(User $me, int $otherId, Collection $exchanges): string
    {
        $mine = $exchanges->first(fn ($e) => (int) $e->requester_id === (int) $me->id && (int) $e->target_id === $otherId);
        $theirs = $exchanges->first(fn ($e) => (int) $e->requester_id === $otherId && (int) $e->target_id === (int) $me->id);

        return match (true) {
            $mine?->status === TripContactExchange::ACCEPTED,
            $theirs?->status === TripContactExchange::ACCEPTED => 'exchanged',
            $theirs?->status === TripContactExchange::PENDING => 'incoming',
            $mine !== null => 'requested',
            default => 'none',
        };
    }

    /**
     * @return Collection<int, TripContactExchange>
     */
    private function exchangesWith(User $me, TripSchedule $schedule): Collection
    {
        return TripContactExchange::where('schedule_id', $schedule->id)
            ->where(fn ($q) => $q->where('requester_id', $me->id)->orWhere('target_id', $me->id))
            ->get();
    }

    private function notify(int $userId, User $from, TripSchedule $schedule, bool $accepted): void
    {
        $name = $from->nickname ?: $from->name;
        $trip = $schedule->trip?->title ?? 'ทริป';

        try {
            $this->fcm->sendToUser(
                $userId,
                $accepted ? '🤝 แลกคอนแทคกันแล้ว' : '🤝 มีคนอยากแลกคอนแทค',
                $accepted
                    ? "{$name} ตอบรับแล้ว — ดู LINE/IG ของเขาได้เลย ({$trip})"
                    : "{$name} จากทริป {$trip} อยากแลกคอนแทคกับคุณ",
                [
                    'type' => 'trip_contact',
                    'schedule_id' => (string) $schedule->id,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('TripContactService: แจ้งเตือนไม่สำเร็จ', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** "@my.ig" → "my.ig", ลิงก์โปรไฟล์ instagram → ชื่อผู้ใช้ — LINE ID เก็บตามที่พิมพ์ */
    private function normalizeHandle(string $key, ?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }

        if (in_array($key, ['instagram', 'tiktok'], true)) {
            $v = preg_replace('~^https?://(www\.)?(instagram\.com|tiktok\.com)/@?~i', '', $v) ?? $v;
            $v = ltrim(rtrim($v, '/'), '@');
        }

        return mb_substr($v, 0, 80) ?: null;
    }
}
