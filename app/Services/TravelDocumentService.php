<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * เอกสารเดินทาง (พาสปอร์ต) ของทริปต่างประเทศ — กฎการตรวจอยู่ที่นี่ที่เดียว
 *
 * ก่อนหน้านี้กฎ "เหลืออายุ 6 เดือนนับจากวันเดินทาง" ถูกเขียนซ้ำอยู่สามที่
 * (CreateBookingRequest ตอนจอง, PublicPassengerFillController ตอนเพื่อนกรอก,
 * PublicPassportController ตอนตามเก็บ) พอเพิ่มช่องทางที่สี่ (แอป) จึงย้ายตัวตรวจ
 * ที่ "แก้ของผู้เดินทางที่มีอยู่แล้ว" มารวมกัน — ทุกช่องทางที่ตามเก็บทีหลังต้อง
 * ตอบลูกค้าด้วยข้อความเดียวกัน ไม่งั้นคนที่กรอกไม่ผ่านในแอปแล้วไปกรอกในเว็บ
 * จะเจอเหตุผลไม่เหมือนกัน
 */
class TravelDocumentService
{
    /** เกณฑ์เดียวกับตอนจอง — สายการบินและ ตม. ส่วนใหญ่ใช้ 6 เดือน */
    public const VALIDITY_MONTHS = 6;

    /** ทริปต่างประเทศเท่านั้นที่ต้องมีพาสปอร์ต */
    public function isRequired(Booking $booking): bool
    {
        return (bool) $booking->schedule?->trip?->isInternational();
    }

    /** วันหมดอายุที่เร็วที่สุดที่ยังรับได้; null เมื่อรอบยังไม่มีวันเดินทาง */
    public function minimumExpiry(Booking $booking): ?Carbon
    {
        return $booking->schedule?->departure_date?->copy()->addMonths(self::VALIDITY_MONTHS);
    }

    /** ผู้เดินทางที่ยังกรอกไม่ครบ — ว่างเสมอสำหรับทริปในประเทศ */
    public function missing(Booking $booking): Collection
    {
        return $booking->passengersMissingPassport();
    }

    /**
     * ผู้เดินทางที่กรอกไว้แล้วแต่พาสปอร์ตจะหมดอายุเร็วเกินเกณฑ์ 6 เดือน
     *
     * ต่างจาก missing() คนละเรื่อง: กรอกครบแต่เล่มใกล้หมดอายุก็ออกตั๋วไม่ได้
     * เหมือนกัน และเป็นกรณีที่โผล่ขึ้นมาเองเมื่อเวลาผ่านไป ไม่ได้ผิดตั้งแต่วันจอง
     */
    public function expiringTooSoon(Booking $booking): Collection
    {
        if (! $this->isRequired($booking)) {
            return collect();
        }

        $minimum = $this->minimumExpiry($booking);
        if (! $minimum) {
            return collect();
        }

        return $booking->passengers->filter(fn (BookingPassenger $passenger) => filled($passenger->passport_expires_at)
            && $passenger->passport_expires_at->lt($minimum));
    }

    /**
     * สรุปสถานะเอกสารของการจอง ในรูปที่ client เอาไปวาดได้เลย
     */
    public function summary(Booking $booking): array
    {
        $required = $this->isRequired($booking);

        return [
            'required' => $required,
            'minimum_expiry' => $this->minimumExpiry($booking)?->toDateString(),
            'validity_months' => self::VALIDITY_MONTHS,
            'missing_count' => $required ? $this->missing($booking)->count() : 0,
            'expiring_count' => $required ? $this->expiringTooSoon($booking)->count() : 0,
        ];
    }

    /**
     * บันทึกเอกสารของผู้เดินทางหลายคนในครั้งเดียว
     *
     * `$rows` คีย์ด้วย id ของผู้เดินทาง คีย์ที่ไม่ได้อยู่ในการจองนี้ถูกทิ้งเงียบ ๆ
     * (ทั้งหน้าเว็บสาธารณะและแอปส่ง id มาจาก payload ที่ตัวเองได้รับ จึงไม่ควร
     * เชื่อว่าเป็น id ของการจองนี้จริง)
     *
     * แถวที่เว้นว่างทั้งแถว = ยังไม่พร้อมกรอกของคนนี้ ข้ามไป ไม่ใช่ error
     * เจอแถวผิดแม้แถวเดียวจะไม่บันทึกอะไรเลย — ลูกค้าจะได้แก้แล้วกดส่งใหม่ทั้งใบ
     * ไม่ต้องเดาว่าอันไหนเข้าไปแล้วอันไหนยัง
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{saved: int, errors: array<int, string>}
     */
    public function apply(Booking $booking, array $rows): array
    {
        $minimumExpiry = $this->minimumExpiry($booking);
        $errors = [];
        $updates = [];

        foreach ($booking->passengers as $passenger) {
            $row = $rows[$passenger->id] ?? $rows[(string) $passenger->id] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $nameEn = trim((string) ($row['name_en'] ?? ''));
            $passportNo = trim((string) ($row['passport_no'] ?? ''));
            $expiresAt = trim((string) ($row['passport_expires_at'] ?? ''));

            if ($nameEn === '' && $passportNo === '' && $expiresAt === '') {
                continue;
            }

            $error = $this->validateRow($nameEn, $passportNo, $expiresAt, $minimumExpiry);
            if ($error) {
                $errors[$passenger->id] = "{$passenger->name}: {$error}";

                continue;
            }

            $updates[$passenger->id] = [
                'name_en' => strtoupper($nameEn),
                'passport_no' => strtoupper($passportNo),
                'passport_expires_at' => $expiresAt,
            ];
        }

        if ($errors) {
            return ['saved' => 0, 'errors' => $errors];
        }

        foreach ($updates as $passengerId => $values) {
            $booking->passengers->firstWhere('id', $passengerId)?->update($values);
        }

        if ($updates) {
            $this->syncBookerProfile($booking->fresh('passengers'));
        }

        return ['saved' => count($updates), 'errors' => []];
    }

    /** คืนข้อความผิดพลาดภาษาไทยของแถวนี้ หรือ null เมื่อกรอกถูกต้อง */
    public function validateRow(string $nameEn, string $passportNo, string $expiresAt, ?Carbon $minimumExpiry): ?string
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
            return 'พาสปอร์ตต้องมีอายุเหลืออย่างน้อย '.self::VALIDITY_MONTHS.' เดือนนับจากวันเดินทาง';
        }

        if ($expiry->lte(now())) {
            return 'พาสปอร์ตหมดอายุแล้ว';
        }

        return null;
    }

    /**
     * คัดลอกพาสปอร์ตของคนจองเอง (แถวที่จับคู่ได้ด้วยเลขบัตร ไม่งั้นชื่อตรงเป๊ะ)
     * ขึ้นไปที่โปรไฟล์ เพื่อให้การจองครั้งหน้าเติมให้อัตโนมัติ
     */
    public function syncBookerProfile(Booking $booking): void
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
}
