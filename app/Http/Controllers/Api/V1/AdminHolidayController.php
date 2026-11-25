<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PublicHoliday;
use App\Services\HolidayCalendarService;
use App\Traits\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * จัดการวันหยุดราชการ (หน้าวันหยุดยาวของลูกค้า) — ส่วนใหญ่ดึงเองจากปฏิทิน Google
 * แอดมินเพิ่มวันหยุดพิเศษที่เพิ่งประกาศ ปิดวันที่ไม่อยากนับ หรือแก้ชื่อที่โชว์ได้
 */
class AdminHolidayController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', (string) CarbonImmutable::now('Asia/Bangkok')->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : CarbonImmutable::now('Asia/Bangkok')->year;

        $rows = PublicHoliday::whereYear('date', $year)->orderBy('date')->orderBy('id')->get();

        return $this->success([
            'year' => $year,
            'holidays' => $rows->map(fn (PublicHoliday $h) => $this->present($h))->values(),
            'last_synced_at' => PublicHoliday::where('source', PublicHoliday::SOURCE_GOOGLE)->max('updated_at'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
        ]);

        $holiday = PublicHoliday::create([
            'date' => $validated['date'],
            'name' => trim($validated['name']),
            'name_en' => filled($validated['name_en'] ?? null) ? trim($validated['name_en']) : null,
            'source' => PublicHoliday::SOURCE_MANUAL,
            'is_active' => true,
            'name_locked' => true,
        ]);

        return $this->success($this->present($holiday), 'เพิ่มวันหยุดแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $holiday = PublicHoliday::findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        // วันที่ของวันหยุดจากปฏิทินแก้ไม่ได้ (การดึงรอบหน้าจะทับ) — ปิดแล้วเพิ่มเองแทน
        if (isset($validated['date']) && $holiday->source === PublicHoliday::SOURCE_GOOGLE) {
            return $this->error('วันหยุดจากปฏิทินแก้วันที่ไม่ได้ — ปิดวันนี้แล้วเพิ่มวันหยุดใหม่แทน', 422);
        }

        if (array_key_exists('name', $validated)) {
            $validated['name'] = trim($validated['name']);
            $validated['name_locked'] = true;
        }
        if (array_key_exists('name_en', $validated)) {
            $validated['name_en'] = filled($validated['name_en']) ? trim($validated['name_en']) : null;
        }

        $holiday->update($validated);

        return $this->success($this->present($holiday->fresh()), 'บันทึกแล้ว');
    }

    public function destroy(int $id): JsonResponse
    {
        $holiday = PublicHoliday::findOrFail($id);

        // ลบวันจากปฏิทินไปก็กลับมาตอนดึงรอบหน้า — ให้ปิดแทน
        if ($holiday->source === PublicHoliday::SOURCE_GOOGLE) {
            return $this->error('วันหยุดจากปฏิทินลบไม่ได้ ใช้ปิดแทน', 422);
        }

        $holiday->delete();

        return $this->success(null, 'ลบวันหยุดแล้ว');
    }

    public function sync(HolidayCalendarService $calendar): JsonResponse
    {
        try {
            $result = $calendar->sync();
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), 502);
        }

        return $this->success($result, "อัปเดตแล้ว — เพิ่ม {$result['created']} แก้ {$result['updated']} ลบ {$result['removed']}");
    }

    /** @return array<string, mixed> */
    private function present(PublicHoliday $holiday): array
    {
        return [
            'id' => $holiday->id,
            'date' => $holiday->date->toDateString(),
            'name' => $holiday->name,
            'name_en' => $holiday->name_en,
            'official_name' => $holiday->official_name,
            'source' => $holiday->source,
            'is_active' => $holiday->is_active,
            'name_locked' => $holiday->name_locked,
        ];
    }
}
