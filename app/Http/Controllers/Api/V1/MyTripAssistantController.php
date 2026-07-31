<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\MyTripAssistantService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ผู้ช่วยส่วนตัว — ตอบคำถามเกี่ยวกับการจองของผู้ใช้ที่ล็อกอินอยู่
 *
 * ต้องล็อกอิน (ต่างจาก /concierge ที่เปิดให้คนยังไม่สมัครถามได้) เพราะคำตอบ
 * อ้างอิงข้อมูลการจองส่วนตัว และคุมด้วย rate limiter 'concierge' เหมือนกัน
 * เพราะทุกคำถามมีค่าใช้จ่ายจริง
 */
class MyTripAssistantController extends Controller
{
    use ApiResponse;

    public function __construct(private MyTripAssistantService $assistant) {}

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:500'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:2000'],
        ], [], [
            'message' => 'คำถาม',
        ]);

        try {
            $answer = $this->assistant->ask(
                $request->user(),
                $data['message'],
                $data['history'] ?? [],
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($answer);
    }

    /** คำถามตัวอย่างสำหรับหน้าจอเปล่า — ไม่เรียกโมเดล จึงไม่มีค่าใช้จ่าย */
    public function suggestions(Request $request): JsonResponse
    {
        return $this->success([
            'suggestions' => $this->assistant->suggestions($request->user())->all(),
        ]);
    }
}
