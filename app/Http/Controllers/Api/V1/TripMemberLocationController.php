<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
use App\Services\TripMemberLocationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ตำแหน่งสดของคนในรอบเดินทาง — เปิดเองได้ ปิดเองได้ตลอด
 *
 * ทุกเส้นทางในนี้ผ่านด่านเดียวกันสองด่านเสมอ: "อยู่ในรอบนี้จริงไหม" และ "ตอนนี้
 * อยู่ในช่วงทริปไหม" ถ้าด่านใดด่านหนึ่งไม่ผ่าน จะอ่านหรือเขียนอะไรก็ไม่ได้
 */
class TripMemberLocationController extends Controller
{
    use ApiResponse;

    public function __construct(private TripMemberLocationService $locations) {}

    public function index(Request $request, int $id): JsonResponse
    {
        $schedule = $this->authorizeSchedule($request, $id);
        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $userId = (int) $request->user()->id;
        $everyone = $this->locations->members($schedule);

        return $this->success([
            'members' => $everyone->reject(fn ($member) => $member['user_id'] === $userId)->values(),
            // ตัวเองยังแชร์อยู่ไหม — แอปใช้ตั้งสวิตช์ให้ตรงกับความจริงฝั่งเซิร์ฟเวอร์
            // หลังปิดแอปแล้วเปิดใหม่
            'sharing' => $everyone->contains(fn ($member) => $member['user_id'] === $userId),
            'stale_after_minutes' => TripMemberLocationService::STALE_MINUTES,
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'speed_kmh' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'altitude_m' => ['nullable', 'numeric', 'between:-500,9000'],
            'battery_level' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $schedule = $this->authorizeSchedule($request, $id);
        if ($schedule instanceof JsonResponse) {
            return $schedule;
        }

        $location = $this->locations->record($schedule, $request->user(), $validated);

        return $this->success([
            'me' => $this->locations->present($location),
            'members' => $this->locations->members($schedule, (int) $request->user()->id),
        ], 'อัปเดตตำแหน่งแล้ว');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        // ตั้งใจไม่เช็คช่วงเวลาตรงนี้: "เลิกแชร์" ต้องทำได้เสมอ แม้ทริปจะจบไปแล้ว
        $schedule = TripSchedule::find($id);
        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        $this->locations->stop($schedule, (int) $request->user()->id);

        return $this->success(null, 'หยุดแชร์ตำแหน่งแล้ว');
    }

    private function authorizeSchedule(Request $request, int $id): TripSchedule|JsonResponse
    {
        $schedule = TripSchedule::find($id);

        if (! $schedule || ! $this->locations->canAccess($schedule, (int) $request->user()->id)) {
            return $this->error('ไม่พบการเดินทางนี้ในบัญชีของคุณ', 404);
        }

        if (! $this->locations->isWithinWindow($schedule)) {
            return $this->error('แชร์ตำแหน่งได้เฉพาะช่วงเวลาทริปเท่านั้น', 422);
        }

        return $schedule;
    }
}
