<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\TravelDocumentService;
use App\Support\Countries;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * เอกสารเดินทางของการจอง — ช่องทางในแอปสำหรับกรอก/แก้พาสปอร์ตหลังจองแล้ว
 *
 * ทำไมต้องมี: ทริปต่างประเทศบังคับพาสปอร์ตตอนจอง แต่ลูกค้าที่ใช้แอปรุ่นก่อนหน้า
 * (ซึ่งไม่มีช่องกรอก) จองผ่านได้โดยยังไม่มีเอกสาร ก่อนหน้านี้เขามีทางเดียวคือลิงก์
 * ในอีเมล — คนที่ลบอีเมลทิ้งหรือไม่เคยเปิดอ่านจึงตกขบวน หน้าจอในแอปที่เขาเปิดอยู่
 * ทุกวันควรถามเองได้
 *
 * เปิดให้ทั้งเจ้าของการจองและเพื่อนร่วมเดินทางแก้ได้ เท่ากับสิทธิ์ของลิงก์ในอีเมล
 * ที่ส่งต่อกันในกลุ่มอยู่แล้ว
 */
class TravelDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TravelDocumentService $documents) {}

    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        return $this->success($this->payload($booking));
    }

    public function store(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        if (! $this->documents->isRequired($booking)) {
            return $this->error('ทริปนี้ไม่ต้องใช้พาสปอร์ต', 422);
        }

        $validated = $request->validate([
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.id' => ['required', 'integer'],
            'passengers.*.name_en' => ['nullable', 'string', 'max:100'],
            'passengers.*.passport_no' => ['nullable', 'string', 'max:20'],
            'passengers.*.passport_expires_at' => ['nullable', 'date'],
        ]);

        $rows = [];
        foreach ($validated['passengers'] as $row) {
            $rows[(int) $row['id']] = [
                'name_en' => $row['name_en'] ?? '',
                'passport_no' => $row['passport_no'] ?? '',
                'passport_expires_at' => $row['passport_expires_at'] ?? '',
            ];
        }

        $result = $this->documents->apply($booking, $rows);

        if ($result['errors']) {
            // ส่งข้อความรายคนกลับไปด้วย แอปจะได้ชี้ช่องที่ผิดให้ตรงคน ไม่ใช่ขึ้น
            // toast รวม ๆ แล้วให้ลูกค้าไล่หาเองว่าใครกรอกผิด
            return $this->error(reset($result['errors']), 422, [
                'passengers' => $result['errors'],
            ]);
        }

        $booking->load(['passengers', 'schedule.trip']);

        return $this->success($this->payload($booking), 'บันทึกเอกสารเดินทางแล้ว');
    }

    private function payload(Booking $booking): array
    {
        $expiringIds = $this->documents->expiringTooSoon($booking)->pluck('id')->all();
        $missingIds = $this->documents->missing($booking)->pluck('id')->all();
        $trip = $booking->schedule?->trip;

        return [
            'booking_ref' => $booking->booking_ref,
            'trip_title' => $trip?->title,
            'country' => $trip?->countryLabel(),
            'departure_date' => $booking->schedule?->departure_date?->toDateString(),
            'passport' => $this->documents->summary($booking),
            'nationalities' => Countries::options(),
            'passengers' => $booking->passengers->map(fn ($passenger) => [
                'id' => $passenger->id,
                'name' => $passenger->name,
                'name_en' => $passenger->name_en,
                'nationality' => $passenger->nationality,
                'passport_no' => $passenger->passport_no,
                'passport_expires_at' => $passenger->passport_expires_at?->toDateString(),
                'is_missing' => in_array($passenger->id, $missingIds, true),
                'is_expiring' => in_array($passenger->id, $expiringIds, true),
            ])->values(),
        ];
    }

    /** คืน Booking หรือ error response เมื่อไม่มีสิทธิ์ */
    private function resolveBooking(Request $request, string $ref): Booking|JsonResponse
    {
        $booking = Booking::with(['passengers', 'schedule.trip', 'user'])
            ->where('booking_ref', $ref)
            ->firstOrFail();

        $user = $request->user();
        $isTeam = $user->hasAnyRole(['admin', 'operator', 'staff']);

        if (! $isTeam && ! $booking->isAccessibleByUser($user->id)) {
            return $this->error('คุณไม่มีสิทธิ์แก้เอกสารเดินทางของการจองนี้', 403);
        }

        if ($booking->status === 'cancelled') {
            return $this->error('การจองนี้ถูกยกเลิกแล้ว', 422);
        }

        return $booking;
    }
}
