<?php

namespace App\Services;

use App\Events\ChatMessageUpdated;
use App\Models\ChatMessage;
use App\Models\ScheduleStaffAssignment;
use App\Models\StaffReview;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * การ์ด "แนะนำทีมงาน" ในห้องแชทของรอบ — ชื่อเล่น เบอร์ ป่าที่เคยเดิน ความถนัด
 * ให้ลูกทริปรู้สึกว่ารู้จักพี่ทีมงานก่อนเจอตัวจริง
 *
 * ข้อมูลสองแหล่ง
 * - สตาฟเขียนเอง (users.staff_bio / staff_trails / staff_skills) ผ่านหน้า
 *   "โปรไฟล์ทีมงาน" ในแอป
 * - ระบบนับให้จากประวัติการคุมรอบ: ทริปที่เคยพาเดิน จำนวนรอบ และคะแนนรีวิว
 *
 * แอปวาดการ์ดจาก present() สด ๆ ทุกครั้งที่โหลดข้อความ ส่วน body() เป็นข้อความ
 * ล้วนสำหรับทุกที่ที่ไม่มีการ์ด (เว็บ หลังบ้าน แอปรุ่นเก่า)
 */
class StaffIntroService
{
    public const BIO_MAX = 300;

    public const TRAILS_MAX = 12;

    public const SKILLS_MAX = 8;

    public const ITEM_MAX = 40;

    /** โชว์ป่าในการ์ดได้ไม่เกินเท่านี้ — ยาวกว่านี้กลายเป็นกำแพงชิป */
    private const TRAILS_SHOWN = 8;

    /** รีวิวไม่กี่อันเฉลี่ยออกมาเป็นตัวเลขที่ไม่มีความหมาย */
    private const MIN_REVIEWS_FOR_RATING = 3;

    /**
     * @return array<string, mixed>
     */
    public function present(User $staff, ?TripSchedule $schedule = null, bool $withPhone = true): array
    {
        $led = $this->ledTrips($staff);
        $rating = $this->rating($staff);

        $manual = $this->cleanList($staff->staff_trails, self::TRAILS_MAX);
        $seen = collect($manual)->map(fn ($t) => mb_strtolower($t))->all();
        $trails = $manual;
        foreach ($led as $trip) {
            if (! in_array(mb_strtolower($trip['name']), $seen, true)) {
                $trails[] = $trip['name'];
                $seen[] = mb_strtolower($trip['name']);
            }
        }

        $phone = trim((string) $staff->phone);

        return [
            'user_id' => $staff->id,
            'name' => $this->displayName($staff),
            'avatar_url' => $staff->avatar_url,
            'phone' => $withPhone && $phone !== '' ? $this->formatPhone($phone) : null,
            'bio' => $this->cleanBio($staff->staff_bio),
            'trails' => array_slice($trails, 0, self::TRAILS_SHOWN),
            'skills' => $this->cleanList($staff->staff_skills, self::SKILLS_MAX),
            'rounds_count' => (int) $led->sum('rounds'),
            'this_trip_rounds' => $schedule
                ? (int) ($led->firstWhere('trip_id', (int) $schedule->trip_id)['rounds'] ?? 0)
                : 0,
            'rating_avg' => $rating['avg'],
            'rating_count' => $rating['count'],
        ];
    }

    /**
     * การ์ดของข้อความ staff_joined:{userId} — null เมื่อไม่ใช่ข้อความแนะนำทีมงาน
     * เบอร์โทรโชว์เฉพาะตอนสตาฟยังประจำรอบอยู่ ปลดออกหลังจบทริปแล้วไม่มีเหตุให้โทร
     *
     * @return array<string, mixed>|null
     */
    public function forMessage(ChatMessage $message): ?array
    {
        $userId = $this->staffIdFromKey($message->system_key);
        if ($userId === null || $message->is_deleted) {
            return null;
        }

        $assignment = ScheduleStaffAssignment::where('schedule_id', $message->schedule_id)
            ->where('user_id', $userId)
            ->first();
        $staff = $assignment ? User::find($userId) : null;
        if (! $staff) {
            return null;
        }

        $schedule = $message->relationLoaded('schedule')
            ? $message->schedule
            : TripSchedule::find($message->schedule_id);

        return $this->present($staff, $schedule, withPhone: $assignment->released_at === null);
    }

    /**
     * ข้อความล้วนของการ์ด — บรรทัดแรกเป็นหัวเรื่อง (แอปแยกบรรทัดแรกเป็นหัวการ์ด)
     *
     * @param  array<string, mixed>  $intro
     */
    public function body(array $intro): string
    {
        $lines = ["🎽 {$intro['name']} จะเป็นทีมงานดูแลรอบนี้ครับ"];

        if ($intro['bio']) {
            $lines[] = $intro['bio'];
        }
        if ($intro['phone']) {
            $lines[] = "📞 โทรหา{$intro['name']}ได้ที่ {$intro['phone']}";
        }
        if ($intro['trails']) {
            $lines[] = '⛰️ ป่าที่เคยเดิน: '.implode(' · ', $intro['trails']);
        }

        $stats = [];
        if ($intro['rounds_count'] > 0) {
            $stats[] = "ดูแลทริปมาแล้ว {$intro['rounds_count']} รอบ"
                .($intro['this_trip_rounds'] > 0 ? " (ทริปนี้ {$intro['this_trip_rounds']} รอบ)" : '');
        }
        if ($intro['rating_avg'] !== null) {
            $stats[] = "⭐ {$intro['rating_avg']} จาก {$intro['rating_count']} รีวิว";
        }
        if ($stats) {
            $lines[] = '🧭 '.implode(' · ', $stats);
        }

        if ($intro['skills']) {
            $lines[] = '🩹 ถนัด: '.implode(' · ', $intro['skills']);
        }

        $lines[] = 'มีอะไรอยากถามก่อนเดินทาง ทักในห้องนี้ได้เลย ทีมงานอ่านทุกข้อความครับ';

        return implode("\n", $lines);
    }

    /**
     * สตาฟแก้โปรไฟล์แล้ว — เขียนข้อความแนะนำตัวในห้องของรอบที่ยังไม่ออกเดินทางใหม่
     * แล้วกระจายให้ห้องที่เปิดอยู่เห็นทันที (รอบที่ผ่านไปแล้วเป็นบันทึก ไม่แตะ)
     */
    public function refreshUpcomingIntros(User $staff): void
    {
        $scheduleIds = $staff->activeAssignedSchedules()->pluck('trip_schedules.id');
        if ($scheduleIds->isEmpty()) {
            return;
        }

        $messages = ChatMessage::where('system_key', "staff_joined:{$staff->id}")
            ->where('is_deleted', false)
            ->whereIn('schedule_id', $scheduleIds)
            ->with('schedule')
            ->get();

        foreach ($messages as $message) {
            try {
                $departsAt = $message->schedule?->effectiveDepartsAt();
                if (! $message->schedule || ($departsAt && $departsAt->isPast())) {
                    continue;
                }

                $body = $this->body($this->present($staff, $message->schedule));
                if ($body === $message->body) {
                    continue;
                }

                $message->forceFill(['body' => $body])->save();
                broadcast(new ChatMessageUpdated($message));
            } catch (\Throwable $e) {
                Log::warning('StaffIntro: อัปเดตข้อความแนะนำตัวไม่สำเร็จ', [
                    'message_id' => $message->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * รายการที่ผู้ใช้กรอก → ตัดช่องว่าง ตัดซ้ำ ตัดความยาว
     *
     * @return array<int, string>
     */
    public function cleanList(mixed $items, int $max): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($items as $item) {
            $text = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $item)), 0, self::ITEM_MAX);
            $key = mb_strtolower($text);
            if ($text === '' || in_array($key, $seen, true)) {
                continue;
            }
            $out[] = $text;
            $seen[] = $key;
            if (count($out) >= $max) {
                break;
            }
        }

        return $out;
    }

    public function cleanBio(?string $bio): ?string
    {
        // ย่อบรรทัดว่างซ้อนกัน — บรรทัดแรกของข้อความระบบคือหัวการ์ด ห้ามมีช่องโหว่
        $text = trim(preg_replace("/\n{2,}/", "\n", str_replace("\r", '', (string) $bio)));

        return $text === '' ? null : mb_substr($text, 0, self::BIO_MAX);
    }

    /**
     * ทริปที่เคยคุมรอบ (รอบที่ออกเดินทางไปแล้วและไม่ถูกยกเลิก) เรียงจากพาเดินบ่อยสุด
     *
     * @return Collection<int, array{trip_id: int, name: string, rounds: int}>
     */
    private function ledTrips(User $staff): Collection
    {
        $today = now('Asia/Bangkok')->toDateString();

        return DB::table('schedule_staff_assignments as a')
            ->join('trip_schedules as s', 's.id', '=', 'a.schedule_id')
            ->join('trips as t', 't.id', '=', 's.trip_id')
            ->where('a.user_id', $staff->id)
            ->where('s.departure_date', '<', $today)
            ->where('s.status', '!=', 'cancelled')
            ->groupBy('t.id', 't.title')
            ->selectRaw('t.id as trip_id, t.title, COUNT(*) as rounds')
            ->orderByDesc('rounds')
            ->orderBy('t.id')
            ->get()
            ->map(fn ($row) => [
                'trip_id' => (int) $row->trip_id,
                'name' => $this->placeName((string) $row->title),
                'rounds' => (int) $row->rounds,
            ]);
    }

    /**
     * @return array{avg: float|null, count: int}
     */
    private function rating(User $staff): array
    {
        $row = StaffReview::where('staff_user_id', $staff->id)
            ->selectRaw('COUNT(*) as total, AVG(rating) as avg_rating')
            ->first();

        $count = (int) ($row?->total ?? 0);

        return [
            'avg' => $count >= self::MIN_REVIEWS_FOR_RATING ? round((float) $row->avg_rating, 1) : null,
            'count' => $count,
        ];
    }

    /**
     * "เดินป่าดอยอินทนนท์ 2 วัน 1 คืน" → "ดอยอินทนนท์" — ชิปต้องสั้นพอเรียงหลายอัน
     */
    private function placeName(string $title): string
    {
        $name = preg_replace('/\s*\d+\s*วัน(\s*\d+\s*คืน)?\s*$/u', '', trim($title));
        $name = preg_replace('/^(ทริป|เดินป่า)\s*/u', '', (string) $name);
        $name = trim((string) $name);

        return mb_substr($name !== '' ? $name : trim($title), 0, self::ITEM_MAX);
    }

    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) === 10 && str_starts_with($digits, '0')
            ? substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6)
            : $phone;
    }

    private function staffIdFromKey(?string $key): ?int
    {
        if (! $key || ! preg_match('/^staff_joined:(\d+)$/', $key, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * ชื่อเล่นก่อน ไม่มีค่อยใช้ชื่อจริงคำแรก — เหมือน ChatRoomEventService
     */
    private function displayName(User $user): string
    {
        $nickname = trim((string) $user->nickname);
        if ($nickname !== '') {
            return $nickname;
        }

        $name = trim((string) $user->name);

        return $name === '' ? 'ทีมงาน' : explode(' ', $name)[0];
    }
}
