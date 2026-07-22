<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SavedTraveller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * สมุดผู้ร่วมเดินทาง — CRUD สำหรับคนที่ผู้ใช้เก็บไว้กรอกซ้ำ
 *
 * ทุก endpoint ผูกกับ user ที่ล็อกอินเสมอ (ไม่มี route ไหนรับ user_id จากภายนอก)
 * เพราะเป็นข้อมูลบัตรประชาชนและข้อมูลสุขภาพของบุคคลที่สาม
 */
class SavedTravellerController extends Controller
{
    use ApiResponse;

    /** กันคนสร้างสมุดรายชื่อขนาดมหาศาลจากการกดผิดซ้ำ ๆ */
    private const MAX_PER_USER = 40;

    public function index(Request $request): JsonResponse
    {
        $travellers = SavedTraveller::where('user_id', $request->user()->id)
            // คนที่ใช้ล่าสุดอยู่บนสุด คนที่ยังไม่เคยใช้เรียงตามเวลาที่เพิ่ม
            ->orderByRaw('last_used_at IS NULL')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SavedTraveller $t) => $this->present($t));

        return $this->success($travellers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $count = SavedTraveller::where('user_id', $request->user()->id)->count();
        if ($count >= self::MAX_PER_USER) {
            return $this->error('สมุดผู้ร่วมเดินทางเต็มแล้ว (สูงสุด '.self::MAX_PER_USER.' คน) กรุณาลบคนที่ไม่ใช้แล้วก่อน', 422);
        }

        $traveller = SavedTraveller::create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return $this->success($this->present($traveller), 'บันทึกลงสมุดผู้ร่วมเดินทางแล้ว', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $traveller = $this->findOwned($request, $id);
        if (! $traveller) {
            return $this->error('ไม่พบผู้ร่วมเดินทางนี้', 404);
        }

        $traveller->update($this->validated($request));

        return $this->success($this->present($traveller), 'อัปเดตข้อมูลแล้ว');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $traveller = $this->findOwned($request, $id);
        if (! $traveller) {
            return $this->error('ไม่พบผู้ร่วมเดินทางนี้', 404);
        }

        $traveller->delete();

        return $this->success(null, 'ลบออกจากสมุดแล้ว');
    }

    /**
     * เก็บผู้โดยสารจากการจองที่ทำไปแล้วเข้าสมุด — ทางที่สะดวกที่สุด เพราะข้อมูล
     * ถูกกรอกครบไปแล้วตอนจอง ผู้ใช้แค่เลือกว่าจะเก็บใครไว้
     *
     * ข้ามคนที่มีอยู่แล้ว (เทียบด้วยชื่อ) เพื่อไม่ให้กดซ้ำแล้วได้รายการซ้ำ
     */
    public function importFromBooking(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->with('passengers')->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        $existing = SavedTraveller::where('user_id', $request->user()->id)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower(trim($name)))
            ->all();

        $created = [];
        $skipped = 0;

        foreach ($booking->passengers as $passenger) {
            $name = trim((string) $passenger->name);
            if ($name === '') {
                continue;
            }

            if (in_array(mb_strtolower($name), $existing, true)) {
                $skipped++;

                continue;
            }

            if (count($existing) + count($created) >= self::MAX_PER_USER) {
                break;
            }

            $created[] = SavedTraveller::create([
                'user_id' => $request->user()->id,
                'title' => $passenger->title,
                'name' => $name,
                'nickname' => $passenger->nickname,
                'phone' => $passenger->phone,
                'email' => $passenger->email,
                'id_card' => $passenger->id_card,
                'birth_date' => $passenger->birth_date?->toDateString(),
                'blood_group' => $passenger->blood_group,
                'emergency_contact' => $passenger->emergency_contact,
                'emergency_phone' => $passenger->emergency_phone,
                'allergies' => $passenger->allergies,
                'health_notes' => $passenger->health_notes,
                'halal_food' => (bool) $passenger->halal_food,
            ]);
        }

        return $this->success([
            'created' => collect($created)->map(fn (SavedTraveller $t) => $this->present($t)),
            'created_count' => count($created),
            'skipped_count' => $skipped,
        ], count($created) > 0
            ? 'เก็บผู้ร่วมเดินทาง '.count($created).' คนเข้าสมุดแล้ว'
            : 'ผู้ร่วมเดินทางทุกคนอยู่ในสมุดอยู่แล้ว');
    }

    /** บันทึกว่าถูกเลือกไปใช้ เพื่อจัดลำดับครั้งถัดไป */
    public function markUsed(Request $request, int $id): JsonResponse
    {
        $traveller = $this->findOwned($request, $id);
        if (! $traveller) {
            return $this->error('ไม่พบผู้ร่วมเดินทางนี้', 404);
        }

        $traveller->markUsed();

        return $this->success($this->present($traveller));
    }

    private function findOwned(Request $request, int $id): ?SavedTraveller
    {
        return SavedTraveller::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->first();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'title' => ['nullable', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:120'],
            'id_card' => ['nullable', 'string', 'max:20'],
            'birth_date' => ['nullable', 'date'],
            'blood_group' => ['nullable', Rule::in(['A', 'B', 'AB', 'O', ''])],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:20'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'health_notes' => ['nullable', 'string', 'max:500'],
            'halal_food' => ['nullable', 'boolean'],
        ]);
    }

    private function present(SavedTraveller $traveller): array
    {
        return [
            'id' => $traveller->id,
            'label' => $traveller->label,
            ...$traveller->toPassengerPayload(),
            'last_used_at' => $traveller->last_used_at?->toIso8601String(),
            'times_used' => $traveller->times_used,
        ];
    }
}
