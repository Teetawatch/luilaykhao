<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PermitForm;
use App\Models\TripSchedule;
use App\Services\PermitSignatureService;
use App\Services\StaffPermitService;
use App\Support\PermitFormFields;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * แบบฟอร์มอุทยานของสตาฟเอง (เมนูงานสตาฟในแอป) — ข้อมูลเติมจากโปรไฟล์ เซ็นได้เฉพาะ
 * ตัวเองในรอบที่ยังรับผิดชอบอยู่ ดู StaffPermitService
 */
class StaffPermitController extends Controller
{
    use ApiResponse;

    public function __construct(private StaffPermitService $permits) {}

    public function show(Request $request, int $id): JsonResponse
    {
        [$schedule, $form] = $this->resolve($request, $id);

        if (! $schedule) {
            return $this->error('ไม่พบรอบนี้ในงานของคุณ', 404);
        }

        if (! $form) {
            return $this->error('รอบนี้ไม่ต้องเซ็นแบบฟอร์มอุทยาน', 404);
        }

        return $this->success($this->permits->preview($schedule, $request->user(), $form));
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'signature' => ['required', 'string', 'max:'.(int) (PermitSignatureService::MAX_BYTES * 1.4)],
            'accept' => ['accepted'],
            'channel' => ['nullable', 'in:app,web'],
            'extra' => ['sometimes', 'array'],
            'extra.*' => ['nullable', 'string', 'max:'.PermitFormFields::ASK_MAX_LENGTH],
        ], [
            'accept.accepted' => 'กรุณาติ๊กยอมรับข้อความในแบบฟอร์มก่อนเซ็น',
        ]);

        [$schedule, $form] = $this->resolve($request, $id);

        if (! $schedule || ! $form) {
            return $this->error('ไม่พบแบบฟอร์มของรอบนี้ในงานของคุณ', 404);
        }

        try {
            $this->permits->sign($this->permits->rowFor($schedule, $request->user()), $form, $validated['signature'], [
                'channel' => $validated['channel'] ?? PermitSignatureService::CHANNEL_APP,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'user_id' => $request->user()->id,
            ], (array) ($validated['extra'] ?? []));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->permits->statusFor($schedule, $request->user()), 'เซ็นแบบฟอร์มของคุณแล้ว');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?PermitForm}
     */
    private function resolve(Request $request, int $id): array
    {
        $schedule = TripSchedule::with('trip.permitForm')
            ->whereKey($id)
            ->whereHas('activeStaff', fn ($q) => $q->where('users.id', $request->user()->id))
            ->first();

        return [$schedule, $schedule ? $this->permits->formFor($schedule) : null];
    }
}
