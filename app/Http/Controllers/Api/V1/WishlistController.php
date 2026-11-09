<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Services\WishlistService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ทริปที่ถูกใจของบัญชี (แอป + เว็บใช้ร่วมกัน) — ดู WishlistService
 */
class WishlistController extends Controller
{
    use ApiResponse;

    public function __construct(private WishlistService $wishlist) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($this->wishlist->listFor($request->user()));
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $trip = Trip::where('slug', $slug)->where('status', 'active')->firstOrFail();

        try {
            $result = $this->wishlist->add($request->user(), $trip);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        $item = $result['item']->setRelation('trip', $trip->load('schedules'));

        return $this->success(
            $this->wishlist->format($item, $result['alert_on']),
            'บันทึกไว้ในทริปที่ถูกใจแล้ว',
            $result['item']->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function destroy(Request $request, string $slug): JsonResponse
    {
        // ทริปที่ถูกซ่อนไปแล้วก็ต้องเอาออกได้ — ไม่กรองสถานะ
        $trip = Trip::where('slug', $slug)->firstOrFail();
        $this->wishlist->remove($request->user(), $trip);

        return $this->success(null, 'นำออกจากทริปที่ถูกใจแล้ว');
    }

    /**
     * รวมรายการที่กดไว้ในเครื่องตอนยังไม่ล็อกอินเข้าบัญชี แล้วคืนรายการทั้งหมด
     */
    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slugs' => ['present', 'array', 'max:'.WishlistService::MAX_ITEMS],
            // ค่าเพี้ยนในเครื่อง (ว่าง) ไม่ควรทำให้หัวใจทั้งก้อนไม่ถูกรวม — service กรองทิ้งเอง
            'slugs.*' => ['nullable', 'string', 'max:255'],
        ]);

        $this->wishlist->merge($request->user(), $validated['slugs']);

        return $this->success($this->wishlist->listFor($request->user()));
    }
}
