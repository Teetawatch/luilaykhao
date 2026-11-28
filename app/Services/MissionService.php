<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Category;
use App\Models\ChallengeCompletion;
use App\Models\MissionCompletion;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ภารกิจรับแต้ม (ระบบแต้มเฟส 2) — ชวนให้ลูกค้ากลับมาจองซ้ำ
 *
 * ภารกิจเป็นชุดตายตัวในโค้ด (แต้มตั้งใน config/loyalty.php missions.points):
 * - comeback      จองทริปถัดไปภายใน N วันหลังวันกลับของทริปที่เพิ่งไป (ทำซ้ำได้ ทุกทริป)
 * - new_activity  ไปทริปกิจกรรมหมวดใหม่ที่ยังไม่เคยไป (ทำซ้ำได้ หมวดละครั้ง ไม่นับหมวดแรก)
 * - profile_complete  กรอกเบอร์ วันเกิด และผู้ติดต่อฉุกเฉินครบ (ครั้งเดียว)
 * - first_review  เขียนรีวิวทริปครั้งแรก (ครั้งเดียว)
 *
 * ไม่มีการสุ่ม — ทุกคนทำเงื่อนไขเดียวกันได้แต้มเท่ากันเสมอ (กล่องสุ่ม/วงล้อเสี่ยงผิด
 * กฎหมายการพนัน) ความคืบหน้าคำนวณสดทุกครั้ง ลูกค้ากด "รับแต้ม" เอง ตอนกดตรวจ
 * เงื่อนไขซ้ำอีกรอบ แถว mission_completions (unique ต่อผู้ใช้/ภารกิจ/ขอบเขต) กันรับซ้ำ
 *
 * ชาเลนจ์รายเดือน/ปี (ChallengeService) ให้แต้มด้วย กดรับผ่าน claimChallenge()
 */
class MissionService
{
    private const TIMEZONE = 'Asia/Bangkok';

    public const KEYS = ['comeback', 'new_activity', 'profile_complete', 'first_review'];

    public function __construct(
        private LoyaltyService $loyalty,
        private ChallengeService $challenges,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('loyalty.missions.enabled', true);
    }

    public function pointsFor(string $key): int
    {
        return max(0, (int) config('loyalty.missions.points.'.$key, 0));
    }

    /**
     * ภารกิจทั้งหมดของผู้ใช้พร้อมสถานะ — state: locked | in_progress | claimable | claimed
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $finished = $this->finishedBookings($user->id);
        $claimed = MissionCompletion::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->get()
            ->keyBy(fn (MissionCompletion $m) => $m->mission_key.'@'.$m->scope_key);

        return array_values(array_filter([
            $this->comeback($user, $finished, $claimed),
            ...$this->newActivity($finished, $claimed),
            $this->profileComplete($user, $claimed),
            $this->firstReview($user, $claimed),
        ]));
    }

    /** แต้มที่กดรับได้ตอนนี้ทั้งหมด (ภารกิจ + ชาเลนจ์) — ป้ายตัวเลขในหน้าแต้มสะสม */
    public function claimablePoints(User $user, ?array $missions = null, ?array $board = null): int
    {
        $missions ??= $this->forUser($user);
        $board ??= $this->challenges->forUser($user->id);

        $fromMissions = collect($missions)->where('state', 'claimable')->sum('points');
        $fromChallenges = collect([...($board['month']['challenges'] ?? []), ...($board['year']['challenges'] ?? [])])
            ->where('points_claimable', true)
            ->sum('points');

        return (int) ($fromMissions + $fromChallenges);
    }

    /**
     * กดรับแต้มของภารกิจ — ตรวจเงื่อนไขซ้ำจากข้อมูลจริง โยน \Exception ถ้ายังรับไม่ได้
     *
     * @return array{points: int, balance: int}
     */
    public function claimMission(User $user, string $key, string $scope): array
    {
        $item = collect($this->forUser($user))
            ->first(fn ($m) => $m['key'] === $key && $m['scope'] === $scope);

        if (! $item || $item['state'] !== 'claimable' || $item['points'] <= 0) {
            throw new \Exception(__('ภารกิจนี้ยังรับแต้มไม่ได้'));
        }

        try {
            return DB::transaction(function () use ($user, $item) {
                $completion = MissionCompletion::create([
                    'user_id' => $user->id,
                    'mission_key' => $item['key'],
                    'scope_key' => $item['scope'],
                    'points' => $item['points'],
                    'reference_type' => $item['reference_id'] ? Booking::class : null,
                    'reference_id' => $item['reference_id'],
                ]);

                $account = $this->loyalty->credit(
                    $user->id,
                    $item['points'],
                    'ภารกิจสำเร็จ: '.$item['title_th'],
                    $completion,
                );

                return ['points' => $item['points'], 'balance' => (int) $account->points];
            });
        } catch (QueryException) {
            // กดพร้อมกันสองเครื่อง — unique index ให้ได้แค่ครั้งเดียว
            throw new \Exception(__('รับแต้มของภารกิจนี้ไปแล้ว'));
        }
    }

    /**
     * กดรับแต้มของชาเลนจ์รายเดือน/ปีที่สำเร็จแล้ว
     *
     * @return array{points: int, balance: int}
     */
    public function claimChallenge(User $user, string $key, string $period): array
    {
        $this->challenges->sync($user->id);

        return DB::transaction(function () use ($user, $key, $period) {
            $completion = ChallengeCompletion::where('user_id', $user->id)
                ->where('challenge_key', $key)
                ->where('period', $period)
                ->lockForUpdate()
                ->first();

            $points = $completion ? $this->challenges->claimablePoints($completion) : 0;

            if ($points <= 0) {
                throw new \Exception(__('ชาเลนจ์นี้ยังรับแต้มไม่ได้'));
            }

            $completion->update(['points_awarded' => $points, 'points_claimed_at' => now()]);

            $account = $this->loyalty->credit(
                $user->id,
                $points,
                'ชาเลนจ์สำเร็จ: '.$this->challenges->definition($key)['title'],
                $completion,
            );

            return ['points' => $points, 'balance' => (int) $account->points];
        });
    }

    /**
     * ใบจองที่ทำให้ภารกิจสำเร็จถูกยกเลิก/คืนเงิน — หักแต้มของภารกิจนั้นคืน (เท่าที่ยัง
     * เหลือ) แล้วเปิดให้ทำภารกิจเดิมได้ใหม่ กันการจองเอาแต้มแล้วยกเลิก
     */
    public function revokeForBooking(Booking $booking): void
    {
        MissionCompletion::where('reference_type', Booking::class)
            ->where('reference_id', $booking->id)
            ->whereNull('revoked_at')
            ->get()
            ->each(function (MissionCompletion $completion) use ($booking) {
                DB::transaction(function () use ($completion, $booking) {
                    $this->loyalty->deduct(
                        $completion->user_id,
                        $completion->points,
                        'ยกเลิกการจอง '.$booking->booking_ref.' — หักแต้มภารกิจคืน',
                        $completion,
                    );

                    // ลบแถวทิ้ง (ไม่ใช่แค่ทำเครื่องหมาย) เพื่อให้ unique index ยอมให้ทำ
                    // ภารกิจขอบเขตเดิมได้อีกครั้งด้วยใบจองใบใหม่
                    $completion->delete();
                });
            });
    }

    // ── ภารกิจแต่ละแบบ ─────────────────────────────────────────────────────────

    /**
     * จองทริปถัดไปภายใน N วันหลังวันกลับ — ดูทริปที่จบแล้วล่าสุดก่อน ใบไหนมีใบจองใหม่
     * ในช่วงเวลาและยังไม่ได้รับแต้ม = รับได้ ไม่งั้นแสดงนับถอยหลังของทริปล่าสุด
     *
     * @param  Collection<int, Booking>  $finished
     * @param  Collection<string, MissionCompletion>  $claimed
     * @return array<string, mixed>|null
     */
    private function comeback(User $user, Collection $finished, Collection $claimed): ?array
    {
        $points = $this->pointsFor('comeback');
        $days = max(1, (int) config('loyalty.missions.comeback_days', 60));

        if ($points <= 0) {
            return null;
        }

        $title = __('กลับมาลุยอีกครั้ง');

        if ($finished->isEmpty()) {
            return $this->item('comeback', 'none', 'กลับมาลุยอีกครั้ง', $title,
                __('หลังจบทริปแรก จองทริปถัดไปภายใน :days วัน รับ :points แต้ม', ['days' => $days, 'points' => $points]),
                'replay', $points, 'locked', action: 'trips');
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $latest = null;

        foreach ($finished->take(5) as $previous) {
            $returned = $this->returnDay($previous);
            $deadline = $returned->addDays($days)->endOfDay();
            $scope = (string) $previous->id;
            $latest ??= ['booking' => $previous, 'deadline' => $deadline, 'scope' => $scope];

            if ($claimed->has('comeback@'.$scope)) {
                // ทริปที่รับแต้มไปแล้วเป็นทริปล่าสุด = ยังไม่มีรอบใหม่ให้ทำ
                if ($previous->is($finished->first())) {
                    return $this->item('comeback', $scope, 'กลับมาลุยอีกครั้ง', $title,
                        __('จองทริปถัดไปภายใน :days วันหลังกลับจากทริป รับ :points แต้ม', ['days' => $days, 'points' => $points]),
                        'replay', $points, 'claimed', action: 'trips');
                }

                continue;
            }

            $next = Booking::query()
                ->where('user_id', $user->id)
                ->whereKeyNot($previous->id)
                ->whereIn('status', LoyaltyService::EARNING_STATUSES)
                ->where('created_at', '>', $returned->timezone('UTC'))
                ->where('created_at', '<=', $deadline->timezone('UTC'))
                ->whereNotIn('id', MissionCompletion::where('mission_key', 'comeback')
                    ->where('user_id', $user->id)
                    ->whereNotNull('reference_id')
                    ->select('reference_id'))
                ->orderBy('created_at')
                ->first();

            if ($next) {
                return $this->item('comeback', $scope, 'กลับมาลุยอีกครั้ง', $title,
                    __('จองทริปถัดไป :ref ภายใน :days วันแล้ว', ['ref' => $next->booking_ref, 'days' => $days]),
                    'replay', $points, 'claimable', referenceId: $next->id, progress: 1.0);
            }
        }

        $deadline = $latest['deadline'];

        if ($today->greaterThan($deadline)) {
            // เลยกำหนดของทริปล่าสุดแล้ว — รอทริปหน้าเปิดรอบใหม่
            return $this->item('comeback', 'none', 'กลับมาลุยอีกครั้ง', $title,
                __('จบทริปหน้าแล้วจองทริปถัดไปภายใน :days วัน รับ :points แต้ม', ['days' => $days, 'points' => $points]),
                'replay', $points, 'locked', action: 'trips');
        }

        $daysLeft = (int) $today->diffInDays($deadline->startOfDay());
        $elapsed = max(0, $days - $daysLeft);

        return $this->item('comeback', $latest['scope'], 'กลับมาลุยอีกครั้ง', $title,
            __('จองทริปถัดไปภายใน :days วันหลังกลับจากทริป รับ :points แต้ม', ['days' => $days, 'points' => $points]),
            'replay', $points, 'in_progress',
            action: 'trips',
            progress: round(min(1, $elapsed / $days), 3),
            deadline: $deadline->toDateString(),
            daysLeft: $daysLeft,
        );
    }

    /**
     * ลองกิจกรรมหมวดใหม่ — หมวดของทริปแรกที่เคยไปไม่นับ หมวดอื่นที่ไปจบแล้ว = รับได้
     * หมวดละครั้ง ถ้าไม่มีอะไรให้รับ แสดงเป็นภารกิจเปิดค้างไว้ชวนลอง
     *
     * @param  Collection<int, Booking>  $finished
     * @param  Collection<string, MissionCompletion>  $claimed
     * @return array<int, array<string, mixed>>
     */
    private function newActivity(Collection $finished, Collection $claimed): array
    {
        $points = $this->pointsFor('new_activity');

        if ($points <= 0) {
            return [];
        }

        $title = __('ลองกิจกรรมใหม่');
        $open = $this->item('new_activity', 'open', 'ลองกิจกรรมใหม่', $title,
            __('ไปทริปกิจกรรมแบบที่ยังไม่เคยไปกับเรา (เช่น ดำน้ำ แคมป์ปิ้ง) รับ :points แต้มต่อหมวด', ['points' => $points]),
            'explore', $points, $finished->isEmpty() ? 'locked' : 'in_progress', action: 'trips');

        if ($finished->isEmpty()) {
            return [$open];
        }

        // ทริปแรกที่เคยไป (เก่าสุด) คือหมวด "ประจำ" ของลูกค้า
        $byOldest = $finished->sortBy(fn (Booking $b) => $this->returnDay($b)->timestamp)->values();
        $home = $byOldest->first()->schedule?->trip?->type;
        $types = $byOldest
            ->map(fn (Booking $b) => ['type' => $b->schedule?->trip?->type, 'booking' => $b])
            ->filter(fn ($row) => filled($row['type']) && $row['type'] !== $home)
            ->unique('type')
            ->values();

        $labels = Category::whereIn('slug', $types->pluck('type'))->get()->keyBy('slug');
        $items = [];

        foreach ($types as $row) {
            if ($claimed->has('new_activity@'.$row['type'])) {
                continue;
            }

            $category = $labels->get($row['type']);
            $label = $category ? $category->tr('name', $category->name) : $row['type'];

            $items[] = $this->item('new_activity', $row['type'], 'ลองกิจกรรมใหม่: '.($category->name ?? $row['type']),
                __('ลองกิจกรรมใหม่: :activity', ['activity' => $label]),
                __('ไปทริปหมวด :activity ครั้งแรกแล้ว', ['activity' => $label]),
                'explore', $points, 'claimable', referenceId: $row['booking']->id, progress: 1.0);
        }

        return $items !== [] ? $items : [$open];
    }

    /**
     * @param  Collection<string, MissionCompletion>  $claimed
     * @return array<string, mixed>|null
     */
    private function profileComplete(User $user, Collection $claimed): ?array
    {
        $points = $this->pointsFor('profile_complete');

        if ($points <= 0) {
            return null;
        }

        $fields = ['phone', 'birth_date', 'emergency_contact', 'emergency_phone'];
        $filled = collect($fields)->filter(fn ($f) => filled($user->{$f}))->count();
        $done = $filled === count($fields);

        $state = match (true) {
            $claimed->has('profile_complete@'.MissionCompletion::SCOPE_ONCE) => 'claimed',
            $done => 'claimable',
            default => 'in_progress',
        };

        return $this->item('profile_complete', MissionCompletion::SCOPE_ONCE, 'โปรไฟล์พร้อมลุย',
            __('โปรไฟล์พร้อมลุย'),
            __('กรอกเบอร์โทร วันเกิด และผู้ติดต่อฉุกเฉินให้ครบ จองครั้งหน้าเร็วขึ้น'),
            'badge', $points, $state,
            action: 'profile',
            progress: round($filled / count($fields), 3),
            current: $filled,
            target: count($fields),
        );
    }

    /**
     * @param  Collection<string, MissionCompletion>  $claimed
     * @return array<string, mixed>|null
     */
    private function firstReview(User $user, Collection $claimed): ?array
    {
        $points = $this->pointsFor('first_review');

        if ($points <= 0) {
            return null;
        }

        $reviewed = Review::where('user_id', $user->id)
            ->whereNull('reviewer_name')
            ->where('is_approved', '!=', false)
            ->exists();

        $state = match (true) {
            $claimed->has('first_review@'.MissionCompletion::SCOPE_ONCE) => 'claimed',
            $reviewed => 'claimable',
            default => 'in_progress',
        };

        return $this->item('first_review', MissionCompletion::SCOPE_ONCE, 'เล่าให้เพื่อนฟัง',
            __('เล่าให้เพื่อนฟัง'),
            __('เขียนรีวิวทริปที่ไปมาแล้วครั้งแรก'),
            'rate_review', $points, $state,
            action: 'review',
            progress: $reviewed ? 1.0 : 0.0,
        );
    }

    // ── ตัวช่วย ────────────────────────────────────────────────────────────────

    /**
     * ใบจองของผู้ใช้ที่ไปจบแล้ว (ยืนยัน/เสร็จ และวันกลับผ่านไปแล้ว) ล่าสุดก่อน
     *
     * @return Collection<int, Booking>
     */
    private function finishedBookings(int $userId): Collection
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        return Booking::query()
            ->where('user_id', $userId)
            ->whereIn('status', LoyaltyService::EARNING_STATUSES)
            ->whereHas('schedule', fn ($q) => $q->whereRaw('COALESCE(return_date, departure_date) < ?', [$today]))
            ->with('schedule.trip:id,type')
            ->get()
            ->sortByDesc(fn (Booking $b) => $this->returnDay($b)->timestamp)
            ->values();
    }

    private function returnDay(Booking $booking): CarbonImmutable
    {
        $date = $booking->schedule?->return_date ?? $booking->schedule?->departure_date;

        return CarbonImmutable::parse($date->toDateString(), self::TIMEZONE)->endOfDay();
    }

    /** @return array<string, mixed> */
    private function item(
        string $key,
        string $scope,
        string $titleTh,
        string $title,
        string $description,
        string $icon,
        int $points,
        string $state,
        ?string $action = null,
        ?int $referenceId = null,
        ?float $progress = null,
        ?string $deadline = null,
        ?int $daysLeft = null,
        ?int $current = null,
        ?int $target = null,
    ): array {
        return [
            'key' => $key,
            'scope' => $scope,
            'title' => $title,
            'title_th' => $titleTh,
            'description' => $description,
            'icon' => $icon,
            'points' => $points,
            'state' => $state,
            'action' => $state === 'claimable' || $state === 'claimed' ? null : $action,
            'progress' => $progress ?? ($state === 'claimed' || $state === 'claimable' ? 1.0 : 0.0),
            'deadline' => $deadline,
            'days_left' => $daysLeft,
            'current' => $current,
            'target' => $target,
            'reference_id' => $referenceId,
        ];
    }
}
