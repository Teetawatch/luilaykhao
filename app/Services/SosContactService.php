<?php

namespace App\Services;

use App\Models\SosAlert;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\Countries;
use App\Support\SiteSettings;
use Illuminate\Support\Collection;

/**
 * เบอร์โทรที่ใช้ได้ตอน "ไม่มีอินเทอร์เน็ต" — ที่เดียว
 *
 * ระบบ SOS ทั้งหมดที่มีอยู่วิ่งบนเน็ต: FCM, Reverb, อีเมล ล้วนต้องการ data
 * ทั้งฝั่งคนกดและฝั่งคนรับ แต่จุดที่ทริปเดินป่าจบลงคือจุดที่ data ไม่มี ขณะที่
 * สัญญาณเสียง/SMS ยังพอมี — คลาสนี้จึงตอบคำถามเดียว: "ในรอบนี้ มีเบอร์อะไร
 * ให้โทรหรือส่ง SMS ได้บ้าง"
 *
 * ใช้สองทางพร้อมกัน
 *   - [forSchedule] รายชื่อที่ส่งไปให้แอปเก็บลงเครื่องล่วงหน้า (ขาออกจากลูกค้า)
 *   - [notifiablePhones] เบอร์ที่เซิร์ฟเวอร์ยิง SMS หาเมื่อมีเคส (ขาเข้าถึงทีม)
 *
 * ทั้งสองทางต้องมาจากนิยามเดียวกัน ไม่งั้นจะเกิดกรณีที่ลูกค้าเห็นเบอร์สตาฟคนหนึ่ง
 * แต่ระบบไปเตือนสตาฟอีกคน — ปัญหาแบบเดียวกับที่ [SosParticipantService] แก้ไป
 * แล้วสำหรับฝั่ง push
 */
class SosContactService
{
    public function __construct(private SosParticipantService $participants) {}

    /**
     * รายชื่อที่ลูกค้าติดต่อได้เมื่อ SOS ส่งไม่ออก เรียงตามลำดับที่ควรลองก่อน:
     * สตาฟที่อยู่ในรอบ → คนขับ → ศูนย์ช่วยเหลือ
     *
     * ไม่รวมผู้ติดต่อฉุกเฉินส่วนตัวของลูกค้าเอง — ข้อมูลนั้นอยู่ในโปรไฟล์ที่แอป
     * ถืออยู่แล้ว และไม่ควรวิ่งผ่านเซิร์ฟเวอร์เพิ่มโดยไม่จำเป็น
     *
     * @return array<int, array{role: string, label: string, name: string|null, phone: string}>
     */
    public function forSchedule(TripSchedule $schedule): array
    {
        $schedule->loadMissing(['vehicle.driver', 'trip']);

        $contacts = [];

        foreach ($schedule->activeStaff as $staff) {
            if (! filled($staff->phone)) {
                continue;
            }

            $contacts[] = [
                'role' => 'staff',
                'label' => 'สตาฟประจำรอบ',
                'name' => $staff->nickname ?: $staff->name,
                'phone' => (string) $staff->phone,
            ];
        }

        $driverPhone = $schedule->vehicle?->driver?->phone
            ?: $schedule->vehicle?->driver_phone;

        if (filled($driverPhone)) {
            $contacts[] = [
                'role' => 'driver',
                'label' => 'คนขับรถ',
                'name' => $schedule->vehicle?->driver?->name
                    ?: $schedule->vehicle?->driver_name,
                'phone' => (string) $driverPhone,
            ];
        }

        $hotline = SiteSettings::supportPhone();

        if (filled($hotline)) {
            $contacts[] = [
                'role' => 'hotline',
                'label' => 'ศูนย์ช่วยเหลือลูกค้า',
                'name' => null,
                'phone' => $hotline,
            ];
        }

        return $this->dedupe($contacts);
    }

    /**
     * เบอร์ฉุกเฉินราชการของประเทศที่รอบนี้ไปอยู่
     *
     * ต่างจากการ์ดในหน้ารายละเอียดทริปตรงที่ทริปในประเทศก็ได้เบอร์ไทยติดไปด้วย
     * ไม่ใช่ค่าว่าง — การ์ดนั้นซ่อนตัวโดยตั้งใจเพราะคนไทยจำ 1669 ได้อยู่แล้ว
     * แต่ปลายทางของข้อมูลชุดนี้คือหน้าจอที่เปิดตอนกำลังตกใจและไม่มีสัญญาณ
     * ซึ่งเป็นคนละสถานการณ์กับการอ่านหน้าทริปอยู่บ้าน
     *
     * @return array<string, string>
     */
    public function emergencyNumbers(TripSchedule $schedule): array
    {
        $schedule->loadMissing('trip');

        $code = $schedule->trip?->isInternational()
            ? $schedule->trip->country_code
            : Countries::HOME;

        return Countries::emergency($code ?: Countries::HOME);
    }

    /**
     * เบอร์ที่เซิร์ฟเวอร์ต้องยิง SMS หาเมื่อมีเคส — สตาฟ คนขับ และทีมออฟฟิศ
     *
     * ไม่ส่งหาผู้เดินทางคนอื่นในรอบโดยตั้งใจ: หนึ่งเคสในรอบ 30 คนจะกลายเป็น
     * 30 ข้อความ และเบอร์ลูกค้าถูกเก็บไว้ใช้เรื่องการจอง ไม่ใช่ไว้ประกาศเหตุ
     * ให้คนแปลกหน้าที่บังเอิญจองรอบเดียวกัน — คนกลุ่มนั้นได้รับผ่าน push อยู่แล้ว
     *
     * @return Collection<int, string> เบอร์รูปแบบ msisdn (66xxxxxxxxx)
     */
    public function notifiablePhones(SosAlert $alert): Collection
    {
        $schedule = $alert->schedule;

        if (! $schedule) {
            return collect();
        }

        $phones = collect($this->forSchedule($schedule))->pluck('phone');

        $opsPhones = User::role(['admin', 'operator'])
            ->whereNotNull('phone')
            ->pluck('phone');

        $senderPhone = $this->msisdn((string) ($alert->contact_phone ?? $alert->user?->phone ?? ''));

        return $phones->merge($opsPhones)
            ->map(fn ($phone) => $this->msisdn((string) $phone))
            ->filter()
            // คนกด SOS ไม่ต้องได้รับ SMS แจ้ง SOS ของตัวเอง
            ->reject(fn (string $phone) => $senderPhone !== null && $phone === $senderPhone)
            ->unique()
            ->values();
    }

    /**
     * แปลงเบอร์ไทยเป็นรูปแบบที่ ThaiBulkSMS รับ — ตรรกะเดียวกับ [SmsService]
     * แต่ใช้กับเบอร์ที่ไม่ได้ผูกกับใบจอง
     */
    public function msisdn(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '66'.substr($digits, 1);
        }

        return $digits;
    }

    /**
     * @param  array<int, array{role: string, label: string, name: string|null, phone: string}>  $contacts
     * @return array<int, array{role: string, label: string, name: string|null, phone: string}>
     */
    private function dedupe(array $contacts): array
    {
        $seen = [];
        $unique = [];

        foreach ($contacts as $contact) {
            $key = $this->msisdn($contact['phone']);

            if ($key === null || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $contact;
        }

        return $unique;
    }
}
