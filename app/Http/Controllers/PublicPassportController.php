<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Carbon\Carbon;
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
 */
class PublicPassportController extends Controller
{
    /** เกณฑ์เดียวกับตอนจอง — สายการบินและ ตม. ส่วนใหญ่ใช้ 6 เดือน */
    private const PASSPORT_VALIDITY_MONTHS = 6;

    public function show(string $token): View
    {
        $booking = $this->resolveBooking($token);

        return view('passport.booking', [
            'booking' => $booking,
            'minExpiry' => $this->minimumExpiry($booking)?->toDateString(),
        ]);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $booking = $this->resolveBooking($token);

        $namesEn = $request->input('name_en', []);
        $passportNos = $request->input('passport_no', []);
        $expiries = $request->input('passport_expires_at', []);
        $minimumExpiry = $this->minimumExpiry($booking);

        $updates = [];

        // เขียนได้เฉพาะผู้เดินทางที่อยู่ในการจองนี้จริง ๆ — คีย์ที่ส่งมาเกินมาถูกทิ้ง
        foreach ($booking->passengers as $passenger) {
            $nameEn = trim((string) ($namesEn[$passenger->id] ?? ''));
            $passportNo = trim((string) ($passportNos[$passenger->id] ?? ''));
            $expiresAt = trim((string) ($expiries[$passenger->id] ?? ''));

            // เว้นว่างทั้งแถว = ยังไม่พร้อมกรอกของคนนี้ ปล่อยไว้ก่อน
            if ($nameEn === '' && $passportNo === '' && $expiresAt === '') {
                continue;
            }

            $error = $this->validateRow($nameEn, $passportNo, $expiresAt, $minimumExpiry);
            if ($error) {
                return back()->withInput()->withErrors([
                    'passengers' => "{$passenger->name}: {$error}",
                ]);
            }

            $updates[$passenger->id] = [
                'name_en' => strtoupper($nameEn),
                'passport_no' => strtoupper($passportNo),
                'passport_expires_at' => $expiresAt,
            ];
        }

        foreach ($updates as $passengerId => $values) {
            $booking->passengers->firstWhere('id', $passengerId)?->update($values);
        }

        $this->syncBookerProfile($booking->fresh('passengers'));

        return redirect()
            ->route('public.passport.show', $token)
            ->with('saved', true);
    }

    /** คืนข้อความผิดพลาดภาษาไทยของแถวนี้ หรือ null เมื่อกรอกถูกต้อง */
    private function validateRow(string $nameEn, string $passportNo, string $expiresAt, ?Carbon $minimumExpiry): ?string
    {
        if ($nameEn === '' || $passportNo === '' || $expiresAt === '') {
            return 'กรุณากรอกให้ครบทั้งชื่อภาษาอังกฤษ เลขที่พาสปอร์ต และวันหมดอายุ';
        }

        if (! preg_match('/^[A-Za-z\s.\'-]+$/', $nameEn)) {
            return 'ชื่อ-สกุลภาษาอังกฤษต้องเป็นตัวอักษรภาษาอังกฤษเท่านั้น';
        }

        if (! preg_match('/^[A-Za-z0-9]{5,20}$/', $passportNo)) {
            return 'เลขที่พาสปอร์ตไม่ถูกต้อง';
        }

        try {
            $expiry = Carbon::parse($expiresAt);
        } catch (\Throwable) {
            return 'วันหมดอายุพาสปอร์ตไม่ถูกต้อง';
        }

        if ($minimumExpiry && $expiry->lt($minimumExpiry)) {
            return 'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย 6 เดือนนับจากวันเดินทาง';
        }

        if ($expiry->lte(now())) {
            return 'พาสปอร์ตหมดอายุแล้ว';
        }

        return null;
    }

    private function minimumExpiry(Booking $booking): ?Carbon
    {
        $departure = $booking->schedule?->departure_date;

        return $departure?->copy()->addMonths(self::PASSPORT_VALIDITY_MONTHS);
    }

    /**
     * คัดลอกพาสปอร์ตของคนจองเอง (แถวที่จับคู่ได้ด้วยเลขบัตร ไม่งั้นชื่อตรงเป๊ะ)
     * ขึ้นไปที่โปรไฟล์ เพื่อให้การจองครั้งหน้าเติมให้อัตโนมัติ
     */
    private function syncBookerProfile(Booking $booking): void
    {
        $user = $booking->user;
        if (! $user) {
            return;
        }

        $own = $booking->passengers->first(function ($passenger) use ($user) {
            return $user->id_card
                ? $passenger->id_card === $user->id_card
                : $passenger->name === $user->name;
        });

        if (! $own || blank($own->passport_no)) {
            return;
        }

        $user->update([
            'name_en' => $own->name_en,
            'passport_no' => $own->passport_no,
            'passport_expires_at' => $own->passport_expires_at?->toDateString(),
        ]);
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

        if (! $booking || $booking->status === 'cancelled' || ! $booking->schedule?->trip?->isInternational()) {
            throw new NotFoundHttpException('ลิงก์นี้ใช้ไม่ได้แล้ว');
        }

        return $booking;
    }
}
