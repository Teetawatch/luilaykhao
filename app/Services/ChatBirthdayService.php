<?php

namespace App\Services;

use App\Models\BookingPassenger;
use App\Models\ChatBirthdayGreeting;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * วันเกิดที่ตรงกับวันเดินทาง — บอกทีมงานล่วงหน้า แล้วให้ทีมงานเป็นคนเลือกว่าจะอวยพร
 * ในห้อง (หรือเตรียมเซอร์ไพรส์เอง) ระบบไม่ประกาศเองเด็ดขาด
 *
 * ใช้ birth_date ของผู้โดยสาร (กรอกไว้ทำประกัน) แค่วันกับเดือน — ไม่มีอายุ ไม่มีปีเกิด
 * หลุดไปไหนทั้งนั้น ทั้งใน payload ของทีมงานและข้อความในห้อง
 */
class ChatBirthdayService
{
    public const TIMEZONE = 'Asia/Bangkok';

    /** บอกทีมงานล่วงหน้ากี่วันก่อนวันออกเดินทาง */
    public const NOTIFY_DAYS_BEFORE = 2;

    public function __construct(
        private TripRosterService $roster,
        private ChatService $chat,
        private FcmService $fcm,
    ) {}

    /**
     * คนที่มีวันเกิดระหว่างวันเดินทาง–วันกลับ (เรียงตามวันที่)
     *
     * @return Collection<int, array{passenger_id: int, name: string, date: string, greeted: bool}>
     */
    public function forSchedule(TripSchedule $schedule): Collection
    {
        $days = $this->tripDays($schedule);
        if ($days->isEmpty()) {
            return collect();
        }

        $travelling = $this->roster->travelling($schedule)->keyBy('passenger_id');
        if ($travelling->isEmpty()) {
            return collect();
        }

        $births = BookingPassenger::whereIn('id', $travelling->keys())
            ->whereNotNull('birth_date')
            ->pluck('birth_date', 'id');

        $greeted = ChatBirthdayGreeting::where('schedule_id', $schedule->id)
            ->whereNotNull('greeted_at')
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $births
            ->map(function ($birth, $passengerId) use ($days, $travelling, $greeted) {
                $birth = CarbonImmutable::parse($birth);
                $day = $days->first(fn (CarbonImmutable $d) => $this->sameBirthday($birth, $d));

                return $day ? [
                    'passenger_id' => (int) $passengerId,
                    'name' => $travelling[$passengerId]['name'],
                    'date' => $day->toDateString(),
                    'greeted' => in_array((int) $passengerId, $greeted, true),
                ] : null;
            })
            ->filter()
            ->sortBy('date')
            ->values();
    }

    /**
     * บอกทีมงานของรอบที่ใกล้ออกเดินทางว่าใครมีวันเกิดในทริป — ครั้งเดียวต่อคน
     * คืนจำนวนคนที่เพิ่งบอกทีมงาน
     */
    public function notifyStaffDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now(self::TIMEZONE);
        $today = $now->toDateString();
        $until = $now->addDays(self::NOTIFY_DAYS_BEFORE)->toDateString();

        $schedules = TripSchedule::query()
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereBetween('departure_date', [$today, $until])
                // รอบที่กำลังเดินทางอยู่ (เพิ่งมีคนจองเข้ามา / ทีมงานเพิ่งถูกจัด)
                ->orWhere(fn ($r) => $r->where('departure_date', '<', $today)->where('return_date', '>=', $today)))
            ->with('trip:id,title')
            ->get();

        $sent = 0;
        foreach ($schedules as $schedule) {
            $sent += $this->notifySchedule($schedule);
        }

        return $sent;
    }

    public function notifySchedule(TripSchedule $schedule): int
    {
        $birthdays = $this->forSchedule($schedule);
        if ($birthdays->isEmpty()) {
            return 0;
        }

        $already = ChatBirthdayGreeting::where('schedule_id', $schedule->id)
            ->whereNotNull('staff_notified_at')
            ->pluck('passenger_id')
            ->map(fn ($id) => (int) $id);
        $fresh = $birthdays->reject(fn ($b) => $already->contains($b['passenger_id']))->values();
        if ($fresh->isEmpty()) {
            return 0;
        }

        $staffIds = $schedule->staff()->pluck('users.id');
        // ยังไม่มีทีมงานประจำรอบ — รอบหน้าค่อยบอก (ยังไม่ตีตราว่าบอกแล้ว)
        if ($staffIds->isEmpty()) {
            return 0;
        }

        $who = $fresh->map(fn ($b) => $b['name'].' ('.ThaiDate::short(CarbonImmutable::parse($b['date'])).')')->implode(', ');
        $trip = $schedule->trip?->title ?? 'ทริป';

        foreach ($staffIds as $staffId) {
            try {
                $this->fcm->sendToUser(
                    (int) $staffId,
                    "🎂 มีวันเกิดในทริป {$trip}",
                    "{$who} — เปิดห้องแชทเพื่อส่งคำอวยพร หรือเตรียมเซอร์ไพรส์ได้เลย",
                    [
                        'type' => 'chat_birthday',
                        'route' => 'chat',
                        'schedule_id' => (string) $schedule->id,
                    ],
                );
            } catch (\Throwable $e) {
                Log::warning('ChatBirthdayService: แจ้งทีมงานไม่สำเร็จ', ['staff_id' => $staffId, 'error' => $e->getMessage()]);
            }
        }

        foreach ($fresh as $b) {
            ChatBirthdayGreeting::updateOrCreate(
                ['schedule_id' => $schedule->id, 'passenger_id' => $b['passenger_id']],
                ['staff_notified_at' => now()],
            );
        }

        return $fresh->count();
    }

    /**
     * ทีมงานส่งคำอวยพรเข้าห้อง — ครั้งเดียวต่อคน (กันกดซ้ำด้วย system_key)
     */
    public function greet(User $staff, TripSchedule $schedule, int $passengerId): ChatMessage
    {
        $birthday = $this->forSchedule($schedule)->firstWhere('passenger_id', $passengerId);
        if (! $birthday) {
            throw new \Exception('ไม่พบวันเกิดของคนนี้ในช่วงทริป');
        }

        $key = "birthday:{$passengerId}";
        $existing = ChatMessage::where('schedule_id', $schedule->id)->where('system_key', $key)->first();
        if ($existing) {
            throw new \Exception('ส่งคำอวยพรของคนนี้ไปแล้ว');
        }

        $date = CarbonImmutable::parse($birthday['date'], self::TIMEZONE);
        $isToday = $date->toDateString() === CarbonImmutable::now(self::TIMEZONE)->toDateString();
        $body = $isToday
            ? "🎂 วันนี้วันเกิดของ {$birthday['name']}! สุขสันต์วันเกิดนะครับ ขอให้ทริปนี้เป็นของขวัญที่ดีที่สุด 🎉\nมาร่วมอวยพรกันหน่อยครับทุกคน"
            : '🎂 '.ThaiDate::short($date)." เป็นวันเกิดของ {$birthday['name']} — ฉลองกันระหว่างทริปเลย 🎉\nมาร่วมอวยพรกันหน่อยครับทุกคน";

        $message = $this->chat->postSystem($schedule, $body, $key);

        ChatBirthdayGreeting::updateOrCreate(
            ['schedule_id' => $schedule->id, 'passenger_id' => $passengerId],
            ['greeted_at' => now(), 'greeted_by_id' => $staff->id],
        );

        return $message;
    }

    /**
     * วันที่ของทริปตามปฏิทินไทย (วันออก…วันกลับ) — เพดาน 31 วันกันรอบที่ข้อมูลผิด
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function tripDays(TripSchedule $schedule): Collection
    {
        if (! $schedule->departure_date) {
            return collect();
        }

        $start = CarbonImmutable::parse($schedule->departure_date->toDateString(), self::TIMEZONE);
        $end = $schedule->return_date
            ? CarbonImmutable::parse($schedule->return_date->toDateString(), self::TIMEZONE)
            : $start;

        $days = collect();
        for ($d = $start; $d->lte($end) && $days->count() < 31; $d = $d->addDay()) {
            $days->push($d);
        }

        return $days;
    }

    /** เกิด 29 ก.พ. — ปีที่ไม่มีวันนั้นฉลองวันที่ 28 ก.พ. */
    private function sameBirthday(CarbonImmutable $birth, CarbonImmutable $day): bool
    {
        if ($birth->month === 2 && $birth->day === 29 && ! $day->isLeapYear()) {
            return $day->month === 2 && $day->day === 28;
        }

        return $birth->month === $day->month && $birth->day === $day->day;
    }
}
