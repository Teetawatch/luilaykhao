<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TripConciergeService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI ผู้ช่วยวางทริป — เปิดให้ถามได้โดยไม่ต้องล็อกอิน (คนยังไม่สมัครก็ต้องหาทริปได้)
 * แต่คุมด้วย rate limiter 'concierge' เพราะทุกคำถามมีค่าใช้จ่ายจริง
 */
class ConciergeController extends Controller
{
    use ApiResponse;

    public function __construct(private TripConciergeService $concierge) {}

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
            $answer = $this->concierge->ask($data['message'], $data['history'] ?? []);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($answer);
    }
}
