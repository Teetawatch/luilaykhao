<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\TravelDocumentService;
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
    public function __construct(private readonly TravelDocumentService $documents) {}

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);

        return view('passport.booking', [
            'booking' => $booking,
            'minExpiry' => $this->documents->minimumExpiry($booking)?->toDateString(),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolveBooking($token);

        $result = $this->documents->apply($booking, $this->rowsFrom($request));

        if ($result['errors']) {
            return back()->withInput()->withErrors([
                'passengers' => reset($result['errors']),
            ]);
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
        $fields = ['name_en', 'passport_no', 'passport_expires_at'];
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
            ->with(['passengers', 'schedule.trip', 'user'])
            ->first();

        if (! $booking || $booking->status === 'cancelled' || ! $this->documents->isRequired($booking)) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $booking;
    }
}
