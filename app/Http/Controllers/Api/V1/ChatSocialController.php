<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMemberIntro;
use App\Models\TripSchedule;
use App\Services\ChatBirthdayService;
use App\Services\ChatIntroService;
use App\Services\ChatService;
use App\Services\TripContactService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ลูกเล่นให้ห้องแชทมีชีวิต — แนะนำตัว, วันเกิดระหว่างทริป (ทีมงาน), แลกคอนแทค
 */
class ChatSocialController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chat,
        private ChatIntroService $intros,
        private ChatBirthdayService $birthdays,
        private TripContactService $contacts,
    ) {}

    // ── แนะนำตัว ───────────────────────────────────────────────────────────

    /** GET schedules/{id}/chat/intro — การ์ดของฉัน (เติมฟอร์มตอนแก้) */
    public function myIntro(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        if (! $this->chat->canAccess($request->user(), $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $intro = $this->intros->mine($request->user(), $schedule);

        return $this->success([
            'intro' => $intro ? $this->intros->present($intro) : null,
            'experiences' => collect(ChatMemberIntro::EXPERIENCES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values()->all(),
        ]);
    }

    /** PUT schedules/{id}/chat/intro */
    public function saveIntro(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'hometown' => ['nullable', 'string', 'max:60'],
            'experience' => ['nullable', Rule::in(array_keys(ChatMemberIntro::EXPERIENCES))],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();
        if (! $this->chat->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ส่งข้อความในห้องนี้', 403);
        }

        try {
            $intro = $this->intros->upsert($user, $schedule, $validated);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'intro' => $this->intros->present($intro),
            'message_id' => $intro->message_id,
        ], 'แนะนำตัวแล้ว');
    }

    // ── วันเกิดระหว่างทริป (ทีมงาน) ───────────────────────────────────────

    /** GET schedules/{id}/chat/birthdays */
    public function birthdays(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้น', 403);
        }

        return $this->success(['items' => $this->birthdays->forSchedule($schedule)->all()]);
    }

    /** POST schedules/{id}/chat/birthdays/{passengerId}/greet */
    public function greet(Request $request, int $scheduleId, int $passengerId): JsonResponse
    {
        $schedule = TripSchedule::findOrFail($scheduleId);
        if (! $this->chat->canModerate($request->user(), $schedule)) {
            return $this->error('เฉพาะทีมงานเท่านั้นที่ส่งคำอวยพรได้', 403);
        }

        try {
            $message = $this->birthdays->greet($request->user(), $schedule, $passengerId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'message' => $this->chat->presentMessage($message, $request->user()->id),
            'items' => $this->birthdays->forSchedule($schedule)->all(),
        ], 'ส่งคำอวยพรแล้ว', 201);
    }

    // ── แลกคอนแทค ─────────────────────────────────────────────────────────

    /** GET schedules/{id}/contacts */
    public function board(Request $request, int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);
        if (! $this->contacts->isParticipant($request->user(), $schedule)) {
            return $this->error('ดูได้เฉพาะคนที่ไปทริปนี้', 403);
        }

        return $this->success([
            'trip_title' => $schedule->trip?->title,
            ...$this->contacts->board($request->user(), $schedule),
        ]);
    }

    /** POST schedules/{id}/contacts/{userId}/request */
    public function requestExchange(Request $request, int $scheduleId, int $userId): JsonResponse
    {
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);

        try {
            $status = $this->contacts->request($request->user(), $schedule, $userId);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['user_id' => $userId, 'status' => $status], $status === 'exchanged' ? 'แลกคอนแทคกันแล้ว' : 'ส่งคำขอแล้ว');
    }

    /** POST schedules/{id}/contacts/{userId}/respond {accept} */
    public function respond(Request $request, int $scheduleId, int $userId): JsonResponse
    {
        $validated = $request->validate(['accept' => ['required', 'boolean']]);
        $schedule = TripSchedule::with('trip:id,title')->findOrFail($scheduleId);

        try {
            $status = $this->contacts->respond($request->user(), $schedule, $userId, (bool) $validated['accept']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['user_id' => $userId, 'status' => $status], $status === 'exchanged' ? 'แลกคอนแทคกันแล้ว' : 'ปฏิเสธแล้ว');
    }

    /** GET me/social-handles */
    public function myHandles(Request $request): JsonResponse
    {
        return $this->success(['handles' => (object) $this->contacts->handlesOf($request->user())]);
    }

    /** PUT me/social-handles */
    public function saveHandles(Request $request): JsonResponse
    {
        $rules = collect(TripContactService::HANDLE_KEYS)
            ->mapWithKeys(fn ($k) => [$k => ['nullable', 'string', 'max:100']])
            ->all();
        $validated = $request->validate($rules);

        return $this->success([
            'handles' => (object) $this->contacts->saveHandles($request->user(), $validated),
        ], 'บันทึกคอนแทคแล้ว');
    }

    /** GET me/trip-contacts — ทุกคนที่แลกกันแล้วทุกทริป */
    public function myContacts(Request $request): JsonResponse
    {
        return $this->success(['items' => $this->contacts->myContacts($request->user())]);
    }
}
