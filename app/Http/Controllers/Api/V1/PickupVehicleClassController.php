<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PickupVehicleClassResource;
use App\Models\PickupVehicleClass;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PickupVehicleClassController extends Controller
{
    use ApiResponse;

    private const CACHE_KEY = 'pickup_vehicle_classes.published';

    /**
     * ไกด์ประเภทรถรับ-ส่งที่ลูกค้าเห็นตอนเลือกจุดรับต่างภูมิภาค
     *
     * ตารางเล็กและแทบไม่เปลี่ยน แต่ถูกเรียกทุกครั้งที่เปิดหน้าจอง จึง cache ไว้
     * เก็บเป็น array (ไม่ใช่ Collection ของ Resource) เพราะ Collection ที่ถูก
     * cache แล้วอ่านกลับมาจะ serialize เป็น JSON ไม่เหมือนเดิม
     */
    public function index(): JsonResponse
    {
        $classes = Cache::remember(self::CACHE_KEY, now()->addHours(6), function () {
            return PickupVehicleClassResource::collection(PickupVehicleClass::published())
                ->resolve();
        });

        return $this->success($classes);
    }

    // ─── Admin ────────────────────────────────────────────────

    public function adminIndex(): JsonResponse
    {
        $classes = PickupVehicleClass::query()->ordered()->get();

        return $this->success(PickupVehicleClassResource::collection($classes));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (PickupVehicleClass::max('sort_order') ?? -1) + 1;
        }

        $class = PickupVehicleClass::create($validated);
        $this->forget();

        return $this->success(new PickupVehicleClassResource($class), 'เพิ่มประเภทรถรับ-ส่งสำเร็จ', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $class = PickupVehicleClass::findOrFail($id);

        $class->update($this->validated($request, $class));
        $this->forget();

        return $this->success(new PickupVehicleClassResource($class), 'อัปเดตประเภทรถรับ-ส่งสำเร็จ');
    }

    public function destroy(int $id): JsonResponse
    {
        PickupVehicleClass::findOrFail($id)->delete();
        $this->forget();

        return $this->success(null, 'ลบประเภทรถรับ-ส่งสำเร็จ');
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->input('ids') as $order => $id) {
            PickupVehicleClass::where('id', $id)->update(['sort_order' => $order]);
        }

        $this->forget();

        return $this->success(null, 'จัดเรียงประเภทรถรับ-ส่งสำเร็จ');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PickupVehicleClass $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        $validated = $request->validate([
            'label' => [$required, 'string', 'max:80'],
            'min_pax' => [$required, 'integer', 'min:1', 'max:255'],
            // ว่าง = "ขึ้นไปไม่จำกัด" จึงต้องยอมรับ null ไม่ใช่บังคับกรอก
            'max_pax' => ['nullable', 'integer', 'min:1', 'max:255'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        // ต้องเทียบกับค่าที่จะเป็นผลลัพธ์จริง ไม่ใช่เฉพาะที่ส่งมาในรีเควสต์นี้
        $min = $validated['min_pax'] ?? $existing?->min_pax;
        $max = array_key_exists('max_pax', $validated) ? $validated['max_pax'] : $existing?->max_pax;

        if ($min !== null && $max !== null && $max < $min) {
            abort(response()->json([
                'success' => false,
                'data' => null,
                'message' => 'จำนวนผู้โดยสารสูงสุดต้องไม่น้อยกว่าจำนวนต่ำสุด',
                'errors' => ['max_pax' => ['จำนวนผู้โดยสารสูงสุดต้องไม่น้อยกว่าจำนวนต่ำสุด']],
            ], 422));
        }

        return $validated;
    }

    private function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
