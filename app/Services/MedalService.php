<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripMedal;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\Countries;
use App\Support\MedalDesign;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * เหรียญพิชิต (Finisher medal) — เหรียญประจำตัวของทริปที่เดินจบจริง
 *
 * ใครได้เหรียญ (ต่อรอบ):
 *  - รอบไม่ถูกยกเลิก และทริปจบแล้ว (20:00 ของวันสุดท้าย — เวลาเดียวกับที่เปิดรีวิว)
 *  - ใบจองสถานะ confirmed/completed
 *  - **ต้องเช็คอินแล้ว** ถ้ารอบนั้นมีการเช็คอินเลยแม้แต่ใบเดียว — เหรียญคือ
 *    หลักฐานว่าไปจริง ใบที่ไม่ได้เช็คอินในรอบที่สตาฟสแกนคือคนที่ไม่มา
 *    แต่ถ้าทั้งรอบไม่มีใครถูกเช็คอินเลย แปลว่ารอบนั้นไม่ได้ใช้ระบบเช็คอิน
 *    (ไม่ใช่ว่าไม่มีใครไป) จึงเชื่อสถานะใบจองแทน ไม่งั้นทั้งรอบจะไม่ได้เหรียญ
 *  - เจ้าของใบจอง + เพื่อนร่วมใบจองที่มีบัญชี (active member) ได้คนละเหรียญ
 *    ยกเว้นเจ้าของที่เป็นแอดมิน/โอเปอเรเตอร์ — นั่นคือใบที่จองแทนลูกค้าจาก
 *    บัญชีตัวเอง เหรียญไม่ใช่ของแอดมิน
 *
 * finisher_no คือ "คนที่เท่าไรที่พิชิตทริปนี้" นับข้ามทุกรอบตามลำดับเวลา ถูกเก็บ
 * ลงแถวและไม่ขยับอีก เพราะเลขนี้ถูกแชร์ออกไปแล้ว — การแจกเลขทุกครั้งจึงไล่รอบ
 * ก่อนหน้าของทริปเดียวกันที่ยังไม่ได้แจกให้เสร็จก่อนเสมอ ([awardSchedule])
 * รอบเก่าจึงได้เลขน้อยกว่ารอบใหม่ แม้ระบบจะเพิ่งเปิดใช้หลังจากนั้นนาน
 */
class MedalService
{
    /** สถานะใบจองที่ถือว่าเดินทางจริง */
    public const LIVE_STATUSES = ['confirmed', 'completed'];

    /** เวลาที่ส่ง push "เหรียญมาแล้ว" — เช้าวันถัดจากวันจบทริป */
    public const NOTIFY_HOUR = 10;

    /** เลยวันจบทริปไปเกินนี้ ไม่ส่ง push แล้ว (เหรียญยังรออยู่ในตู้ตามปกติ) */
    public const NOTIFY_WINDOW_DAYS = 3;

    /** รอบที่จบไม่เกินนี้ถูกตรวจซ้ำทุกชั่วโมง — รับการเช็คอินย้อนหลัง/เพื่อนที่ตามมาทีหลัง */
    public const RECENT_DAYS = 45;

    private const TIMEZONE = 'Asia/Bangkok';

    // ── ใครได้เหรียญ ─────────────────────────────────────────────────────────

    public function hasEnded(TripSchedule $schedule): bool
    {
        return $schedule->status !== 'cancelled'
            && ($schedule->return_date ?? $schedule->departure_date) !== null
            && $schedule->isReviewAvailable();
    }

    /**
     * ผู้ได้เหรียญของรอบนี้ เรียงตามลำดับที่จะได้เลข (เช็คอินก่อนได้ก่อน)
     *
     * @return array<int, int> user_id => booking_id
     */
    public function eligibleHolders(TripSchedule $schedule): array
    {
        if (! $this->hasEnded($schedule)) {
            return [];
        }

        $bookings = Booking::query()
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', self::LIVE_STATUSES)
            ->with([
                'user.roles',
                'members' => fn ($q) => $q->where('status', BookingMember::STATUS_ACTIVE)
                    ->whereNotNull('user_id')
                    ->orderBy('id'),
            ])
            ->get();

        $checkedIn = fn (Booking $b) => (bool) $b->checked_in || $b->checked_in_at !== null;
        $usesCheckIn = $bookings->contains($checkedIn);

        $ordered = $bookings
            ->filter(fn (Booking $b) => ! $usesCheckIn || $checkedIn($b))
            ->sort(function (Booking $a, Booking $b) {
                // เช็คอินก่อนได้เลขก่อน ใบที่ไม่มีเวลาเช็คอินไปต่อท้าย แล้วค่อยตามลำดับใบจอง
                $at = $a->checked_in_at?->getTimestamp() ?? PHP_INT_MAX;
                $bt = $b->checked_in_at?->getTimestamp() ?? PHP_INT_MAX;

                return [$at, $a->id] <=> [$bt, $b->id];
            });

        $holders = [];

        foreach ($ordered as $booking) {
            $owner = $booking->user;

            if ($owner && ! $owner->hasAnyRole(['admin', 'operator'])) {
                $holders[(int) $owner->id] ??= (int) $booking->id;
            }

            foreach ($booking->members as $member) {
                $holders[(int) $member->user_id] ??= (int) $booking->id;
            }
        }

        return $holders;
    }

    // ── แจกเหรียญ ────────────────────────────────────────────────────────────

    /**
     * ทำให้เหรียญของรอบนี้ตรงกับความจริง — สร้างที่ขาด ย้ายที่เปลี่ยนเจ้าของ
     * ลบที่ไม่ควรมี คืนจำนวนเหรียญที่สร้างใหม่
     *
     * ล็อกแถวทริปไว้ตลอด เพราะเลข finisher_no ของทริปหนึ่งต้องแจกทีละคน
     */
    public function awardSchedule(TripSchedule $schedule): int
    {
        return DB::transaction(function () use ($schedule) {
            Trip::whereKey($schedule->trip_id)->lockForUpdate()->first();

            $created = 0;

            foreach ($this->unawardedEarlierSchedules($schedule) as $earlier) {
                $created += $this->reconcile($earlier);
            }

            return $created + $this->reconcile($schedule);
        });
    }

    /**
     * รอบก่อนหน้าของทริปเดียวกันที่จบแล้วแต่ยังไม่เคยแจกเหรียญ — ต้องแจกก่อน
     * รอบนี้ ไม่งั้นรอบเก่าจะได้เลขมากกว่ารอบใหม่
     *
     * @return Collection<int, TripSchedule>
     */
    private function unawardedEarlierSchedules(TripSchedule $schedule): Collection
    {
        $departure = $schedule->departure_date?->toDateString();

        if ($departure === null) {
            return collect();
        }

        return TripSchedule::query()
            ->where('trip_id', $schedule->trip_id)
            ->where('id', '!=', $schedule->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($q) use ($departure, $schedule) {
                $q->whereDate('departure_date', '<', $departure)
                    ->orWhere(fn ($q) => $q->whereDate('departure_date', $departure)
                        ->where('id', '<', $schedule->id));
            })
            ->whereNotIn('id', TripMedal::query()->select('schedule_id'))
            ->whereHas('bookings', fn ($q) => $q->whereIn('status', self::LIVE_STATUSES))
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (TripSchedule $s) => $this->hasEnded($s))
            ->values();
    }

    private function reconcile(TripSchedule $schedule): int
    {
        $holders = $this->eligibleHolders($schedule);
        $existing = TripMedal::where('schedule_id', $schedule->id)->get()->keyBy('user_id');

        // คนที่ถือเหรียญอยู่แล้วและยังควรได้ — แค่ตามใบจองให้ตรง
        foreach ($existing as $userId => $medal) {
            if (! array_key_exists($userId, $holders)) {
                continue;
            }

            if ((int) $medal->booking_id !== $holders[$userId]) {
                $medal->update(['booking_id' => $holders[$userId]]);
            }

            unset($holders[$userId]);
            $existing->forget($userId);
        }

        // เหรียญที่เจ้าของเดิมไม่ควรถืออีกแล้ว: ถ้าใบจองเดียวกันมีคนใหม่ที่ยังไม่มี
        // เหรียญ (ใบจองถูกโอน/ย้ายเข้าบัญชีจริง) ส่งเหรียญต่อให้เขาพร้อมเลขเดิม
        // เลขนั้นเป็นของที่นั่งที่ไปจริง ไม่ใช่ของบัญชี
        $orphans = $existing->values();

        foreach ($holders as $userId => $bookingId) {
            $index = $orphans->search(fn (TripMedal $m) => (int) $m->booking_id === $bookingId);

            if ($index === false) {
                continue;
            }

            $orphans->pull($index)->update([
                'user_id' => $userId,
                // ลิงก์ที่เจ้าของเดิมแชร์ไปต้องไม่กลายเป็นหน้าของคนอื่น
                'share_token' => TripMedal::newShareToken(),
                'seen_at' => null,
                'notified_at' => null,
            ]);
            unset($holders[$userId]);
        }

        foreach ($orphans as $orphan) {
            $orphan->delete();
        }

        if ($holders === []) {
            return 0;
        }

        $next = (int) TripMedal::where('trip_id', $schedule->trip_id)->max('finisher_no');
        $earnedOn = ($schedule->return_date ?? $schedule->departure_date)->toDateString();

        foreach ($holders as $userId => $bookingId) {
            TripMedal::create([
                'user_id' => $userId,
                'trip_id' => $schedule->trip_id,
                'schedule_id' => $schedule->id,
                'booking_id' => $bookingId,
                'finisher_no' => ++$next,
                'earned_on' => $earnedOn,
                'share_token' => TripMedal::newShareToken(),
            ]);
        }

        return count($holders);
    }

    /**
     * รอบที่ควรถูกตรวจในงานรายชั่วโมง: จบไม่นาน (รับการเช็คอินย้อนหลัง) หรือยัง
     * ไม่เคยได้แจกเลย (รอบเก่าทั้งหมดตอนเปิดใช้ระบบครั้งแรก) เรียงเก่า → ใหม่
     *
     * @return Collection<int, TripSchedule>
     */
    public function schedulesToAward(): Collection
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $since = CarbonImmutable::now(self::TIMEZONE)->subDays(self::RECENT_DAYS)->toDateString();

        return TripSchedule::query()
            ->where('status', '!=', 'cancelled')
            ->whereDate('departure_date', '<=', $today)
            ->whereHas('bookings', fn ($q) => $q->whereIn('status', self::LIVE_STATUSES))
            ->where(function ($q) use ($since) {
                $q->whereDate('departure_date', '>=', $since)
                    ->orWhereDate('return_date', '>=', $since)
                    ->orWhereNotIn('id', TripMedal::query()->select('schedule_id'));
            })
            ->orderBy('departure_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (TripSchedule $s) => $this->hasEnded($s))
            ->values();
    }

    /**
     * ตรวจเฉพาะรอบของผู้ใช้คนนี้ที่สถานะเหรียญน่าจะไม่ตรง — เรียกก่อนเปิดตู้เหรียญ
     * ทุกครั้ง เพื่อให้คนที่เพิ่งถูกเพิ่มเข้าใบจอง/เพิ่งถูกเช็คอินย้อนหลังเห็นเหรียญ
     * ทันที โดยไม่ต้องรองานรายชั่วโมง
     */
    public function syncUser(int $userId): void
    {
        $memberBookingIds = BookingMember::where('user_id', $userId)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->pluck('booking_id');

        $candidates = Booking::query()
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhereIn('id', $memberBookingIds))
            ->whereIn('status', self::LIVE_STATUSES)
            ->with('schedule')
            ->get()
            ->pluck('schedule')
            ->filter(fn (?TripSchedule $s) => $s !== null && $this->hasEnded($s))
            ->keyBy('id');

        $held = TripMedal::where('user_id', $userId)->pluck('schedule_id')->flip();

        $toCheck = collect();

        foreach ($candidates as $scheduleId => $schedule) {
            // ถือเหรียญอยู่แล้ว — ไม่มีอะไรต้องทำ
            if ($held->has($scheduleId)) {
                continue;
            }

            // ยังไม่มีเหรียญ: ตรวจก่อนว่าควรได้จริงไหม (อาจเป็นใบที่ไม่ได้เช็คอิน)
            // จะได้ไม่ต้องเปิดทรานแซกชันทุกครั้งที่เปิดตู้เหรียญ
            if (array_key_exists($userId, $this->eligibleHolders($schedule))) {
                $toCheck->put($scheduleId, $schedule);
            }
        }

        // ถือเหรียญของรอบที่ไม่ควรถือแล้ว (ใบจองถูกยกเลิก/ออกจากใบจอง)
        foreach ($held->keys() as $scheduleId) {
            if (! $candidates->has($scheduleId)) {
                $schedule = TripSchedule::find($scheduleId);

                if ($schedule) {
                    $toCheck->put($scheduleId, $schedule);
                }
            }
        }

        foreach ($toCheck->sortBy(fn (TripSchedule $s) => [$s->departure_date?->timestamp, $s->id]) as $schedule) {
            try {
                $this->awardSchedule($schedule);
            } catch (\Throwable $e) {
                // ตู้เหรียญต้องเปิดได้เสมอ — รอบที่แจกไม่สำเร็จจะถูกงานรายชั่วโมงเก็บตก
                Log::warning('Medal sync failed', [
                    'user_id' => $userId,
                    'schedule_id' => $schedule->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── แจ้งเตือน ────────────────────────────────────────────────────────────

    /**
     * ส่ง push "เหรียญมาแล้ว" ตอนเช้าวันถัดจากวันจบทริป
     *
     * ไม่ส่งคืนที่ทริปจบ เพราะคืนนั้นมีชวนรีวิว (20:00) กับอวยพรเดินทางกลับ
     * (20:15) อยู่แล้ว — เช้าวันรุ่งขึ้นคนพักแล้ว และเป็นเวลาที่อยากโพสต์รูปทริป
     * เหรียญที่เจ้าของเปิดดูไปแล้ว หรือของทริปที่จบนานแล้ว (แจกย้อนหลัง) ไม่ส่ง
     */
    public function notifyPending(): int
    {
        $now = CarbonImmutable::now(self::TIMEZONE);
        $sent = 0;

        TripMedal::query()
            ->whereNull('notified_at')
            ->with(['trip', 'schedule'])
            ->chunkById(200, function (Collection $medals) use ($now, &$sent) {
                foreach ($medals as $medal) {
                    $opensAt = CarbonImmutable::parse($medal->earned_on->toDateString(), self::TIMEZONE)
                        ->addDay()
                        ->setTime(self::NOTIFY_HOUR, 0);
                    $closesAt = $opensAt->addDays(self::NOTIFY_WINDOW_DAYS);

                    if ($medal->seen_at !== null || $now->greaterThanOrEqualTo($closesAt) || ! $medal->trip) {
                        $medal->update(['notified_at' => now()]);

                        continue;
                    }

                    if ($now->lessThan($opensAt)) {
                        continue;
                    }

                    $name = MedalDesign::name($medal->trip);

                    SmartNotification::send(
                        $medal->user_id,
                        'medal_earned',
                        '🏅 เหรียญพิชิตมาถึงแล้ว!',
                        "คุณคือ Finisher #{$medal->finisher_no} ของ {$name} "
                            .'เหรียญเก็บอยู่ในตู้เหรียญของคุณแล้ว แตะเพื่อดูและแชร์ความภูมิใจได้เลย',
                        [
                            'medal_id' => $medal->id,
                            'trip_id' => $medal->trip_id,
                            'schedule_id' => $medal->schedule_id,
                            'route' => 'medal',
                        ],
                    );

                    $medal->update(['notified_at' => now()]);
                    $sent++;
                }
            });

        return $sent;
    }

    // ── อ่าน ────────────────────────────────────────────────────────────────

    /**
     * ตู้เหรียญของผู้ใช้ — ใหม่สุดก่อน
     *
     * @return array{medals: array<int, array<string, mixed>>, total: int, unseen_count: int, trips_count: int}
     */
    public function forUser(int $userId): array
    {
        $this->syncUser($userId);

        $medals = TripMedal::query()
            ->where('user_id', $userId)
            ->with(['trip', 'schedule', 'booking:id,booking_ref', 'user'])
            ->get()
            ->filter(fn (TripMedal $m) => $m->trip && $m->schedule && $m->user);

        // "ครั้งที่ 2" — คนที่ไปทริปเดิมซ้ำได้เหรียญทุกครั้ง นับตามลำดับเวลา
        $attempts = [];
        $chron = $medals->sortBy(fn (TripMedal $m) => [$m->earned_on->timestamp, $m->id]);

        foreach ($chron as $medal) {
            $attempts[$medal->trip_id] = ($attempts[$medal->trip_id] ?? 0) + 1;
            $medal->setAttribute('attempt', $attempts[$medal->trip_id]);
        }

        $list = $medals
            ->sortByDesc(fn (TripMedal $m) => [$m->earned_on->timestamp, $m->id])
            ->map(fn (TripMedal $m) => $this->present($m, $attempts[$m->trip_id] ?? 1))
            ->values()
            ->all();

        return [
            'medals' => $list,
            'total' => count($list),
            'unseen_count' => $medals->whereNull('seen_at')->count(),
            'trips_count' => $medals->pluck('trip_id')->unique()->count(),
        ];
    }

    /**
     * หนึ่งเหรียญสำหรับเจ้าของ (API ส่วนตัว) — มีเลขที่จองได้ ส่วนหน้าสาธารณะ
     * ใช้ [forShareToken] ซึ่งไม่มี
     *
     * @return array<string, mixed>
     */
    public function present(TripMedal $medal, int $attemptsOfTrip = 1): array
    {
        return [
            'id' => $medal->id,
            'seen' => $medal->seen_at !== null,
            'booking_ref' => $medal->booking?->booking_ref,
            ...$this->publicCard($medal),
            'attempt' => (int) ($medal->getAttribute('attempt') ?? 1),
            'attempts_of_trip' => $attemptsOfTrip,
            'share_url' => url('/m/'.$medal->share_token),
        ];
    }

    /**
     * ข้อมูลเหรียญที่เปิดเผยได้ — ทุกอย่างในนี้ถูกโพสต์สาธารณะ จึงไม่มีเลขที่จอง
     * เบอร์ จุดรับ หรือยอดเงิน มีแค่สิ่งที่พิมพ์อยู่บนเหรียญ
     *
     * @return array<string, mixed>
     */
    public function publicCard(TripMedal $medal): array
    {
        $trip = $medal->trip;
        $schedule = $medal->schedule;

        return [
            'finisher_no' => $medal->finisher_no,
            'finisher_label' => 'Finisher #'.$medal->finisher_no,
            'earned_on' => $medal->earned_on->toDateString(),
            'earned_label' => ThaiDate::full($medal->earned_on),
            'date_label' => ThaiDate::range($schedule?->departure_date, $schedule?->return_date),
            'holder_name' => $this->holderName($medal->user),
            'design' => MedalDesign::forTrip($trip),
            'trip' => [
                'id' => $trip->id,
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'country_flag' => $trip->isInternational() ? Countries::flag($trip->country_code) : null,
                'country_name' => $trip->isInternational() ? Countries::name($trip->country_code) : null,
                'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
                'elevation_gain_m' => $trip->elevation_gain_m,
                'duration_days' => $trip->duration_days,
                'cover_image' => MediaDisk::url($trip->cover_image ?: $trip->thumbnail_image),
            ],
        ];
    }

    /**
     * การ์ดของหน้าสาธารณะ /m/{token} — null เมื่อไม่มีเหรียญนี้ (หรือถูกถอนไปแล้ว)
     *
     * @return array<string, mixed>|null
     */
    public function forShareToken(string $token): ?array
    {
        $medal = TripMedal::query()
            ->with(['trip', 'schedule', 'user'])
            ->where('share_token', mb_strtolower(trim($token)))
            ->first();

        if (! $medal || ! $medal->trip || ! $medal->user) {
            return null;
        }

        return $this->publicCard($medal);
    }

    /**
     * เหรียญของคนนี้สำหรับหน้าโปรไฟล์สาธารณะ — เรียงใหม่ก่อน
     *
     * @return array<int, array<string, mixed>>
     */
    public function shelfFor(int $userId, int $limit = 12): array
    {
        return TripMedal::query()
            ->where('user_id', $userId)
            ->with(['trip', 'schedule', 'user'])
            ->orderByDesc('earned_on')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(fn (TripMedal $m) => $m->trip && $m->user)
            ->map(fn (TripMedal $m) => [
                ...$this->publicCard($m),
                'share_url' => url('/m/'.$m->share_token),
            ])
            ->values()
            ->all();
    }

    /**
     * ชื่อบนเหรียญ — ชื่อเล่นก่อน (ชื่อที่คนในทริปเรียกกันจริง) แล้วค่อยชื่อบัญชี
     * ตรงกับที่โปรไฟล์สาธารณะใช้
     */
    public function holderName(?User $user): string
    {
        $name = trim((string) ($user?->nickname ?: $user?->name));

        return $name !== '' ? $name : 'นักเดินทาง';
    }

    /**
     * ทำเครื่องหมายว่าเห็นแล้ว (ปิดฉากฉลองเหรียญใหม่) — [$ids] ว่าง = ทั้งหมด
     */
    public function markSeen(int $userId, array $ids = []): int
    {
        return TripMedal::query()
            ->where('user_id', $userId)
            ->whereNull('seen_at')
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->update(['seen_at' => now()]);
    }
}
