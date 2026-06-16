<?php

namespace App\Services;

use App\Events\ScheduleAnnouncementPosted;
use App\Jobs\SendAnnouncementPushJob;
use App\Models\AnnouncementRead;
use App\Models\ScheduleAnnouncement;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ประกาศทางการจากผู้จัดต่อรอบเดินทาง (เปลี่ยนจุดนัดพบ / เลื่อนเวลา / ของที่ต้อง
 * เตรียม ฯลฯ) — โพสต์ได้เฉพาะสตาฟ/ทีมงาน, กระจาย push + เรียลไทม์ให้สมาชิกรอบ,
 * และติดตามสถานะอ่าน/ยังไม่อ่านต่อผู้ใช้ การควบคุมสิทธิ์เข้าถึงใช้ร่วมกับห้องแชท
 * (ChatService) เพื่อให้สมาชิกรอบเดียวกันเห็นทั้งแชทและประกาศ
 */
class AnnouncementService
{
    public const CATEGORIES = [
        'general', 'meeting_point', 'schedule_change', 'packing', 'weather', 'urgent',
    ];

    public function __construct(
        private ChatService $chatService,
    ) {}

    public function canAccess(User $user, TripSchedule $schedule): bool
    {
        return $this->chatService->canAccess($user, $schedule);
    }

    public function canModerate(User $user, TripSchedule $schedule): bool
    {
        return $this->chatService->canModerate($user, $schedule);
    }

    /**
     * โพสต์ประกาศใหม่ แล้วกระจายเรียลไทม์ + push ให้สมาชิกรอบทั้งหมด
     */
    public function post(
        TripSchedule $schedule,
        User $author,
        string $category,
        string $title,
        string $body,
        bool $pinned = false,
    ): ScheduleAnnouncement {
        $announcement = ScheduleAnnouncement::create([
            'schedule_id' => $schedule->id,
            'author_id' => $author->id,
            'category' => in_array($category, self::CATEGORIES, true) ? $category : 'general',
            'title' => trim($title),
            'body' => trim($body),
            'is_pinned' => $pinned,
        ]);

        // ผู้โพสต์ถือว่าอ่านประกาศของตัวเองแล้ว
        $this->markRead($author, $schedule, $announcement->id);

        broadcast(new ScheduleAnnouncementPosted($announcement))->toOthers();
        SendAnnouncementPushJob::dispatch($announcement->id, $author->id);

        return $announcement;
    }

    public function update(
        ScheduleAnnouncement $announcement,
        string $category,
        string $title,
        string $body,
    ): ScheduleAnnouncement {
        $announcement->update([
            'category' => in_array($category, self::CATEGORIES, true) ? $category : $announcement->category,
            'title' => trim($title),
            'body' => trim($body),
        ]);

        return $announcement->fresh();
    }

    public function setPinned(ScheduleAnnouncement $announcement, bool $pinned): ScheduleAnnouncement
    {
        $announcement->update(['is_pinned' => $pinned]);

        return $announcement->fresh();
    }

    public function delete(ScheduleAnnouncement $announcement): void
    {
        $announcement->delete();
    }

    /**
     * รายการประกาศของรอบ — ปักหมุดขึ้นก่อน จากนั้นใหม่สุดอยู่บน
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function list(TripSchedule $schedule): Collection
    {
        return ScheduleAnnouncement::with('author:id,name,nickname,avatar')
            ->where('schedule_id', $schedule->id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($a) => $this->present($a));
    }

    public function markRead(User $user, TripSchedule $schedule, ?int $announcementId = null): void
    {
        $latestId = $announcementId
            ?? (int) ScheduleAnnouncement::where('schedule_id', $schedule->id)->max('id');

        $record = AnnouncementRead::firstOrNew([
            'schedule_id' => $schedule->id,
            'user_id' => $user->id,
        ]);

        // เลื่อน marker ไปข้างหน้าเท่านั้น ไม่ถอยหลัง
        if ($latestId > (int) $record->last_read_announcement_id) {
            $record->last_read_announcement_id = $latestId;
            $record->save();
        }
    }

    public function unreadCount(User $user, TripSchedule $schedule): int
    {
        $lastRead = (int) AnnouncementRead::where('schedule_id', $schedule->id)
            ->where('user_id', $user->id)
            ->value('last_read_announcement_id');

        return ScheduleAnnouncement::where('schedule_id', $schedule->id)
            ->where('id', '>', $lastRead)
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ScheduleAnnouncement $announcement): array
    {
        $author = $announcement->author;

        return [
            'id' => $announcement->id,
            'schedule_id' => $announcement->schedule_id,
            'category' => $announcement->category,
            'title' => $announcement->title,
            'body' => $announcement->body,
            'is_pinned' => (bool) $announcement->is_pinned,
            'author_name' => $author?->nickname ?: $author?->name ?: 'ทีมงาน',
            'author_avatar_url' => $author?->avatar_url,
            'created_at' => $announcement->created_at?->toISOString(),
            'updated_at' => $announcement->updated_at?->toISOString(),
        ];
    }
}
