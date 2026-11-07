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
}
