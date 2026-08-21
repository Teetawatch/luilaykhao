<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Services\BookingDocumentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ไฟล์เอกสารที่แนบมากับการจอง — บางทริปต้องใช้ (ใบรับรองแพทย์ สำเนาบัตร
 * ใบอนุญาตดำน้ำ ฯลฯ) แอดมินเป็นคนกำหนดเองว่าทริปไหนขออะไร และเขียนหมายเหตุ
 * "ใช้สำหรับ..." ได้เอง
 *
 * แนบตอนจองก็ได้ ตามมาแนบทีหลังจากหน้ารายละเอียดการจองก็ได้ — เอกสารจริง
 * บางใบ (เช่น ใบรับรองแพทย์) ลูกค้ายังไม่มีในมือตอนกดจอง การบังคับให้มีครบ
 * ก่อนจึงเท่ากับไล่ลูกค้ากลับ
 *
 * สิทธิ์เท่ากับ TravelDocumentController: เจ้าของ เพื่อนร่วมเดินทางที่รับคำเชิญ
 * แล้ว และทีมงาน
 */
class BookingDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BookingDocumentService $documents) {}

    public function index(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        return $this->success($this->documents->payload($booking));
    }

    public function store(Request $request, string $ref): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        if (! $this->documents->isRequired($booking)) {
            return $this->error('ทริปนี้ไม่ได้ขอเอกสารแนบ', 422);
        }

        $validated = $request->validate([
            'passenger_id' => ['required', 'integer'],
            'requirement_key' => ['required', 'string', 'max:64'],
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', BookingDocumentService::ALLOWED_MIMES),
                'max:'.BookingDocumentService::MAX_SIZE_KB,
            ],
        ], [
            'file.mimes' => 'รองรับไฟล์รูปภาพ และ PDF เท่านั้น',
            'file.max' => 'ไฟล์ต้องมีขนาดไม่เกิน '.(int) round(BookingDocumentService::MAX_SIZE_KB / 1024).' MB',
        ]);

        // ผู้เดินทางต้องอยู่ในการจองนี้ — ไม่งั้นจะแนบไฟล์ข้ามการจองกันได้
        $passenger = $booking->passengers->firstWhere('id', (int) $validated['passenger_id']);

        if (! $passenger) {
            return $this->error('ไม่พบผู้เดินทางรายนี้ในการจอง', 422);
        }

        try {
            $document = $this->documents->store(
                booking: $booking,
                passenger: $passenger,
                requirementKey: $validated['requirement_key'],
                file: $request->file('file'),
                uploadedById: $request->user()->id,
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success([
            'file' => $this->documents->filePayload($document),
            'documents' => $this->documents->payload($booking->load('documents')),
        ], 'แนบเอกสารแล้ว', 201);
    }

    public function destroy(Request $request, string $ref, int $documentId): JsonResponse
    {
        $booking = $this->resolveBooking($request, $ref);

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        $document = BookingDocument::where('booking_id', $booking->id)
            ->where('id', $documentId)
            ->first();

        if (! $document) {
            return $this->error('ไม่พบเอกสารรายการนี้', 404);
        }

        $this->documents->delete($document);

        return $this->success(
            $this->documents->payload($booking->load('documents')),
            'ลบเอกสารแล้ว',
        );
    }

    /** คืน Booking หรือ error response เมื่อไม่มีสิทธิ์ */
    private function resolveBooking(Request $request, string $ref): Booking|JsonResponse
    {
        $booking = Booking::with(['passengers', 'documents', 'schedule.trip'])
            ->where('booking_ref', $ref)
            ->firstOrFail();

        $user = $request->user();
        $isTeam = $user->hasAnyRole(['admin', 'operator', 'staff']);

        if (! $isTeam && ! $booking->isAccessibleByUser($user->id)) {
            return $this->error('คุณไม่มีสิทธิ์ดูเอกสารของการจองนี้', 403);
        }

        return $booking;
    }
}
