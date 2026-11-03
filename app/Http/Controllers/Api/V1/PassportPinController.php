<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PassportPin;
use App\Services\PassportPinService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * หมุดความทรงจำในสมุดสะสมการเดินทาง — ปักตรงที่ที่ไปมาจริง + แนบรูป
 * เห็น/แก้ได้เฉพาะเจ้าของหมุด
 */
class PassportPinController extends Controller
{
    use ApiResponse;

    public function __construct(private PassportPinService $pins) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success(
            $this->pins->pinsFor($request->user()->id)
                ->map(fn (PassportPin $pin) => $this->pins->format($pin))
                ->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(PassportPinService::rules() + PassportPinService::photoRules());

        try {
            $pin = $this->pins->create($request->user(), $data, $request->file('photos', []));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->pins->format($pin), 'ปักหมุดแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $pin = $this->owned($request, $id);
        $data = $request->validate(PassportPinService::rules(partial: true));

        try {
            $pin = $this->pins->update($pin, $data);
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->pins->format($pin), 'บันทึกแล้ว');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->pins->delete($this->owned($request, $id));

        return $this->success(null, 'ลบหมุดแล้ว');
    }

    public function addPhotos(Request $request, int $id): JsonResponse
    {
        $pin = $this->owned($request, $id);
        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.PassportPinService::MAX_PHOTOS_PER_PIN],
        ] + PassportPinService::photoRules());

        try {
            $pin = $this->pins->addPhotos($pin, $request->file('photos', []));
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success($this->pins->format($pin), 'เพิ่มรูปแล้ว');
    }

    public function destroyPhoto(Request $request, int $id, int $photoId): JsonResponse
    {
        $pin = $this->pins->deletePhoto($this->owned($request, $id), $photoId);

        return $this->success($this->pins->format($pin), 'ลบรูปแล้ว');
    }

    /** หมุดของคนอื่นตอบ 404 เหมือนไม่มีอยู่ — ไม่บอกว่ามีหมุดเลขนี้ */
    private function owned(Request $request, int $id): PassportPin
    {
        return PassportPin::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
