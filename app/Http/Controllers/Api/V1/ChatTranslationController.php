<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Services\ChatTranslationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * กด "แปล" ที่ข้อความในแชททริป — ลูกทริปจีน/ฝรั่งอ่านข้อความไทย และสตาฟอ่าน
 * ข้อความภาษาอื่นเป็นไทย ใช้ปุ่มเดียวกัน ต่างกันแค่ภาษาปลายทางที่ส่งมา
 */
class ChatTranslationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ChatService $chatService,
        private ChatTranslationService $translations,
    ) {}

    public function store(Request $request, int $scheduleId, int $messageId): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'string', Rule::in(array_keys(ChatTranslationService::LANGUAGES))],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $message = ChatMessage::where('schedule_id', $scheduleId)->findOrFail($messageId);

        try {
            $result = $this->translations->translate($message, $validated['lang']);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($result, 'แปลแล้ว');
    }

    /**
     * คำแปลอัตโนมัติของข้อความที่แอปกำลังแสดง (เปิดห้อง / เลื่อนดูข้อความเก่า)
     * — ที่มีแล้วได้ทันที ที่ยังไม่มีเข้าคิว ผลตามมาทาง chat.message.translated
     */
    public function batch(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'string', Rule::in(array_keys(ChatTranslationService::LANGUAGES))],
            'message_ids' => ['required', 'array', 'max:50'],
            'message_ids.*' => ['integer'],
        ]);

        $schedule = TripSchedule::findOrFail($scheduleId);
        $user = $request->user();

        if (! $this->chatService->canAccess($user, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403);
        }

        $result = $this->translations->backlog(
            $schedule,
            $user,
            $validated['lang'],
            array_values(array_unique(array_map('intval', $validated['message_ids']))),
        );

        return $this->success([
            'lang' => $validated['lang'],
            // object เสมอ — ว่างก็ {} ไม่ใช่ [] ให้แอปอ่านเป็น map ได้ทุกครั้ง
            'translations' => (object) $result['translations'],
            'pending' => $result['pending'],
        ]);
    }

    /**
     * ตั้งภาษาที่อ่านแชท + เปิด/ปิดแปลอัตโนมัติ (ทุกห้องของผู้ใช้) — ห้องที่เขา
     * อยู่ลืมชุดภาษาเดิม ข้อความถัดไปจะได้แปลตามค่าที่เพิ่งตั้ง
     */
    public function settings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'string', Rule::in(array_keys(ChatTranslationService::LANGUAGES))],
            'auto' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'chat_translate_lang' => $validated['lang'],
            'chat_auto_translate' => (bool) $validated['auto'],
        ])->save();

        foreach ($this->chatService->userScheduleIds($user) as $scheduleId) {
            $this->translations->forgetRoom((int) $scheduleId);
        }

        return $this->success([
            'lang' => $user->chat_translate_lang,
            'auto' => (bool) $user->chat_auto_translate,
        ], 'บันทึกแล้ว');
    }
}
