<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\BookingDocumentService;
use App\Services\TravelDocumentService;
use App\Support\TripDocumentRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * หน้ากรอกเอกสารเดินทางของทั้งการจอง เข้าผ่านลิงก์เฉพาะการจอง (passport_token)
 *
 * มีไว้ตามเก็บพาสปอร์ตของทริปต่างประเทศที่จองเข้ามาจากช่องทางที่ยังถามไม่ได้
 * — แอปรุ่นก่อนที่จะมีช่องกรอก ซึ่งลูกค้าอีกจำนวนหนึ่งยังใช้อยู่และจะใช้ต่อไป
 * อีกพักใหญ่ ปล่อยให้จองสำเร็จก่อนแล้วมากรอกทีหลัง ดีกว่าให้กดจองไม่ผ่าน
 *
 * ไม่ต้องล็อกอิน ไม่ต้องมีแอป เปิดจากลิงก์ในอีเมลได้เลย และกรอกซ้ำ/แก้ได้จนกว่า
 * จะถึงวันเดินทาง เพราะพาสปอร์ตเล่มใหม่ระหว่างรอเดินทางเป็นเรื่องปกติ
 *
 * กฎการตรวจอยู่ที่ TravelDocumentService — หน้านี้กับ API ของแอปใช้ตัวเดียวกัน
 */
class PublicPassportController extends Controller
{
    public function __construct(
        private readonly TravelDocumentService $documents,
        private readonly BookingDocumentService $files,
    ) {}

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);

        return view('passport.booking', [
            'booking' => $booking,
            'minExpiry' => $this->documents->minimumExpiry($booking)?->toDateString(),
            // บัตรหน้า/หลังที่แนบไว้แล้วของแต่ละคน [passenger_id => [key => true]]
            'idFiles' => $booking->documents
                ->whereIn('requirement_key', TripDocumentRequirements::BORDER_PASS_KEYS)
                ->groupBy('booking_passenger_id')
                ->map(fn ($rows) => $rows->pluck('requirement_key')->flip()->map(fn () => true)->all())
                ->all(),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolveBooking($token);

        // ไฟล์บัตรประชาชน (Border Pass) — ตรวจชนิด/ขนาดก่อนแตะอะไรทั้งนั้น
        $request->validate([
            'border_pass_front' => ['nullable', 'array'],
            'border_pass_back' => ['nullable', 'array'],
            'border_pass_front.*' => ['nullable', 'file', 'mimes:'.implode(',', BookingDocumentService::ALLOWED_MIMES), 'max:'.BookingDocumentService::MAX_SIZE_KB],
            'border_pass_back.*' => ['nullable', 'file', 'mimes:'.implode(',', BookingDocumentService::ALLOWED_MIMES), 'max:'.BookingDocumentService::MAX_SIZE_KB],
        ], [
            'border_pass_front.*.mimes' => 'บัตรประชาชนรองรับไฟล์รูปภาพ และ PDF เท่านั้น',
            'border_pass_back.*.mimes' => 'บัตรประชาชนรองรับไฟล์รูปภาพ และ PDF เท่านั้น',
            'border_pass_front.*.max' => 'ไฟล์บัตรประชาชนต้องไม่เกิน 10 MB',
            'border_pass_back.*.max' => 'ไฟล์บัตรประชาชนต้องไม่เกิน 10 MB',
        ]);

        $result = $this->documents->apply($booking, $this->rowsFrom($request));

        if ($result['errors']) {
            return back()->withInput()->withErrors([
                'passengers' => reset($result['errors']),
            ]);
        }

        // แนบหลังบันทึกคำตอบแล้ว — ไฟล์บัตรรับเฉพาะคนที่ตอนนี้เป็น Border Pass
        $booking->load(['passengers', 'documents']);
        foreach ([
            'border_pass_front' => TripDocumentRequirements::BORDER_PASS_FRONT,
            'border_pass_back' => TripDocumentRequirements::BORDER_PASS_BACK,
        ] as $field => $key) {
            foreach ((array) $request->file($field, []) as $passengerId => $file) {
                $passenger = $booking->passengers->firstWhere('id', (int) $passengerId);
                if (! $passenger || ! $file) {
                    continue;
                }

                try {
                    $this->files->store($booking, $passenger, $key, $file, $booking->user_id);
                } catch (\Exception $e) {
                    return back()->withErrors(['passengers' => "{$passenger->name}: {$e->getMessage()}"]);
                }
            }
        }

        return redirect()
            ->route('public.passport.show', $token)
            ->with('saved', true);
    }

    /**
     * ฟอร์มเว็บส่งมาเป็นอาเรย์แยกช่อง (name_en[id], passport_no[id], ...)
     * สลับให้เป็นแถวต่อคนก่อนส่งเข้า service
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function rowsFrom(Request $request): array
    {
        $fields = ['name_en', 'passport_no', 'passport_expires_at', 'travel_document'];
        $rows = [];

        foreach ($fields as $field) {
            $values = $request->input($field, []);
            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $passengerId => $value) {
                $rows[$passengerId][$field] = $value;
            }
        }

        return $rows;
    }

    /**
     * ลิงก์ที่ใช้ไม่ได้ตอบ 404 เหมือนลิงก์มั่ว และการจองที่ยกเลิกไปแล้วก็ไม่ต้อง
     * กรอกอีก ส่วนทริปในประเทศไม่เคยมีลิงก์นี้อยู่แล้ว
     */
    private function resolveBooking(string $token): Booking
    {
        $booking = Booking::where('passport_token', trim($token))
            ->with(['passengers', 'documents', 'schedule.trip', 'user'])
            ->first();

        if (! $booking || $booking->status === 'cancelled' || ! $this->documents->isRequired($booking)) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $booking;
    }
}
