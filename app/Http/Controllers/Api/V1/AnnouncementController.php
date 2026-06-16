<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ScheduleAnnouncement;
use App\Models\TripSchedule;
use App\Services\AnnouncementService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ประกาศจากผู้จัดต่อรอบเดินทาง — ฝั่งลูกค้าอ่าน/ทำเครื่องหมายอ่าน, ฝั่งทีมงาน
 * (admin/operator/สตาฟประจำรอบ) โพสต์/แก้ไข/ปักหมุด/ลบ
 */
class AnnouncementController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AnnouncementService $service,
    ) {}

    // ── Customer side ─────────────────────────────────────────────────────────

    public function index(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูประกาศของรอบนี้', 403);
        }

        return $this->success([
            'announcements' => $this->service->list($schedule)->all(),
            'can_moderate' => $this->service->canModerate($user, $schedule),
            'unread_count' => $this->service->unreadCount($user, $schedule),
        ]);
    }

    public function markRead(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูประกาศของรอบนี้', 403);
        }

        $this->service->markRead($user, $schedule, $request->integer('announcement_id') ?: null);

        return $this->success(['unread_count' => 0], 'อ่านแล้ว');
    }

    public function unreadCount(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูประกาศของรอบนี้', 403);
        }

        return $this->success(['count' => $this->service->unreadCount($user, $schedule)]);
    }

    // ── Operator side ─────────────────────────────────────────────────────────

    public function store(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(AnnouncementService::CATEGORIES)],
            'title' => ['required', 'string', 'max:140'],
            'body' => ['required', 'string', 'max:4000'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canModerate($user, $schedule)) {
            return $this->error('เฉพาะทีมงานหรือสตาฟประจำรอบเท่านั้นที่โพสต์ประกาศได้', 403);
        }

        $announcement = $this->service->post(
            $schedule,
            $user,
            $validated['category'] ?? 'general',
            $validated['title'],
            $validated['body'],
            (bool) ($validated['is_pinned'] ?? false),
        );

        return $this->success($this->service->present($announcement), 'โพสต์ประกาศแล้ว', 201);
    }

    public function update(Request $request, int $scheduleId, int $announcementId): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['nullable', Rule::in(AnnouncementService::CATEGORIES)],
            'title' => ['required', 'string', 'max:140'],
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canModerate($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์แก้ไขประกาศนี้', 403);
        }

        $announcement = ScheduleAnnouncement::where('schedule_id', $scheduleId)->findOrFail($announcementId);
        $updated = $this->service->update(
            $announcement,
            $validated['category'] ?? $announcement->category,
            $validated['title'],
            $validated['body'],
        );

        return $this->success($this->service->present($updated), 'แก้ไขประกาศแล้ว');
    }

    public function pin(Request $request, int $scheduleId, int $announcementId): JsonResponse
    {
        return $this->togglePin($request, $scheduleId, $announcementId, true);
    }

    public function unpin(Request $request, int $scheduleId, int $announcementId): JsonResponse
    {
        return $this->togglePin($request, $scheduleId, $announcementId, false);
    }

    private function togglePin(Request $request, int $scheduleId, int $announcementId, bool $pinned): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canModerate($user, $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่ปักหมุดประกาศได้', 403);
        }

        $announcement = ScheduleAnnouncement::where('schedule_id', $scheduleId)->findOrFail($announcementId);
        $updated = $this->service->setPinned($announcement, $pinned);

        return $this->success(
            $this->service->present($updated),
            $pinned ? 'ปักหมุดประกาศแล้ว' : 'ปลดหมุดประกาศแล้ว',
        );
    }

    public function destroy(Request $request, int $scheduleId, int $announcementId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->service->canModerate($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ลบประกาศนี้', 403);
        }

        $announcement = ScheduleAnnouncement::where('schedule_id', $scheduleId)->findOrFail($announcementId);
        $this->service->delete($announcement);

        return $this->success(['deleted' => true], 'ลบประกาศแล้ว');
    }
}
