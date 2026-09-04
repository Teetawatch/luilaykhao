<?php

namespace App\Services;

use App\Jobs\NotifyStalledIntakesJob;
use App\Models\Booking;
use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\IntakeLink;
use App\Models\TripSchedule;
use Illuminate\Support\Facades\DB;

/**
 * รับข้อมูลที่ลูกค้ากรอกเองผ่านลิงก์ แล้วเก็บไว้จนกว่าแอดมินจะดึงไปเปิดการจอง
 *
 * ตรรกะที่สำคัญที่สุดในนี้คือ "กรอกซ้ำต้องทับของเดิม ไม่ใช่เพิ่มแถวใหม่" —
 * ลูกค้ากดลิงก์เดิมซ้ำ กรอกผิดแล้วกรอกใหม่ หรือเพื่อนคนเดียวกันส่งสองรอบ
 * ล้วนเกิดขึ้นจริง ถ้าไม่รวมให้ แอดมินจะได้รายชื่อซ้ำมานั่งไล่ลบเอง
 */
class CustomerIntakeService
{
    /** กันลิงก์กลุ่มที่หลุดออกไปถูกกรอกถล่มจนกลายเป็นถังขยะ */
    public const MAX_PEOPLE = 20;

    public function __construct(private readonly MailService $mail) {}

    /**
     * คนแรกเปิดลิงก์สาธารณะแล้วกรอก — เปิดกลุ่มใหม่ หรือกลับเข้ากลุ่มเดิมของตัวเอง
     *
     * @param  array<string, mixed>  $data
     */
    public function openFromLink(IntakeLink $link, array $data, ?TripSchedule $schedule): CustomerIntake
    {
        $intake = DB::transaction(function () use ($link, $data, $schedule) {
            $phone = $this->normalisePhone($data['phone'] ?? '');
            $bookingType = $data['booking_type'] ?? CustomerIntake::TYPE_NORMAL;

            // เบอร์เดิม + รอบเดิม + ประเภทเดิม + ยังไม่ถูกดึงไปจอง = คนเดิมกลับมา
            // ประเภทต้องตรงด้วย เพราะคนที่กรอกไว้แบบจองปกติแล้วกลับมากรอกลิงก์จอย
            // คือคนละใบจอง (ใบจองหนึ่งใบเป็นได้ประเภทเดียว) ไม่ใช่การกรอกซ้ำ
            $intake = CustomerIntake::open()
                ->where('contact_phone', $phone)
                ->where('trip_schedule_id', $schedule?->id)
                ->where('booking_type', $bookingType)
                ->latest('id')
                ->first();

            if (! $intake) {
                $intake = new CustomerIntake([
                    'intake_link_id' => $link->id,
                    'trip_schedule_id' => $schedule?->id,
                    'booking_type' => $bookingType,
                    'contact_name' => $data['name'],
                    'contact_phone' => $phone,
                    'contact_email' => $data['email'] ?? null,
                    'party_size' => max(1, (int) ($data['party_size'] ?? 1)),
                    'source' => $data['source'] ?? null,
                    'note' => $data['note'] ?? null,
                    'status' => 'new',
                ]);
                $intake->token = CustomerIntake::mintToken();
                $intake->last_activity_at = now();
                $intake->save();

                $link->markUsed();
            } else {
                $intake->fill([
                    'contact_name' => $data['name'],
                    'contact_email' => $data['email'] ?? $intake->contact_email,
                    'party_size' => max(1, (int) ($data['party_size'] ?? $intake->party_size)),
                    // หมายเหตุที่ส่งมารอบใหม่ต่อท้ายของเดิม ไม่ทับ — ลูกค้ามักเพิ่ม
                    // เงื่อนไขทีหลัง ("ขอที่นั่งติดกันด้วย") และของเดิมยังต้องอยู่
                    'note' => $this->mergeNote($intake->note, $data['note'] ?? null),
                ])->save();
            }

            $this->upsertPerson($intake, $data, isLead: true);
            $intake->touchActivity();

            return $intake->fresh();
        });

        $this->notifyTeamIfReady($intake);

        return $intake;
    }

    /**
     * เพื่อนในกลุ่มเปิดลิงก์ของกลุ่มแล้วกรอกข้อมูลของตัวเอง
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Exception เมื่อกลุ่มเต็มแล้ว
     */
    public function addToGroup(CustomerIntake $intake, array $data): CustomerIntakePerson
    {
        $person = DB::transaction(function () use ($intake, $data) {
            $phone = $this->normalisePhone($data['phone'] ?? '');
            $existing = $this->findPerson($intake, $phone, $data['name']);

            if (! $existing && $intake->people()->count() >= self::MAX_PEOPLE) {
                throw new \Exception('กลุ่มนี้กรอกครบจำนวนสูงสุดแล้ว กรุณาติดต่อทีมงาน');
            }

            $person = $this->upsertPerson($intake, $data, isLead: false);

            // มากันเกินที่แจ้งไว้ตอนแรกก็ขยายให้เอง ไม่ต้องให้แอดมินมาแก้ตัวเลข
            $filled = $intake->people()->count();
            if ($filled > $intake->party_size) {
                $intake->forceFill(['party_size' => $filled])->save();
            }

            $intake->touchActivity();

            return $person;
        });

        $this->notifyTeamIfReady($intake->fresh());

        return $person;
    }

    /**
     * ใบจองที่ดึงกลุ่มนี้ไปเปิดไว้ตายไปทั้งที่ยังไม่เคยยืนยัน — คืนกลุ่มกลับมาให้ดึงใหม่
     *
     * กรณีที่เกิดจริงบ่อยที่สุดคือลูกค้าสแกนจ่ายไม่ทันหน้าต่างชำระเงิน ระบบยกเลิก
     * ใบจองอัตโนมัติแล้วคืนที่นั่ง แต่ตัวกลุ่มยังค้างเป็น "จองแล้ว" — ทีมงานที่กลับ
     * ไปหาข้อมูลลูกค้าคนนั้นจะเจอแค่หมวดที่ไม่มีปุ่มดึงไปจอง ทั้งที่ความจริงคือ
     * ลูกค้ายังไม่ได้จองอะไรเลย
     *
     * เฉพาะกลุ่มที่สถานะยัง "จองแล้ว" เท่านั้น — กลุ่มที่แอดมินเก็บเข้ากรุเองตั้งใจ
     * ปิดมัน ไม่ใช่หน้าที่ของการยกเลิกใบจองที่จะไปรื้อกลับมา
     *
     * @return int จำนวนกลุ่มที่กลับมารอ
     */
    public function reopenForFailedBooking(Booking $booking): int
    {
        $intakes = CustomerIntake::where('booking_id', $booking->id)
            ->where('status', 'booked')
            ->get()
            ->filter(fn (CustomerIntake $intake) => $intake->reopen());

        foreach ($intakes as $intake) {
            // ตีตราว่าบอกทีมงานแล้วสำหรับรอบการรอครั้งนี้ ก่อนส่งจริง — กลุ่มที่กลับ
            // มารอเงียบ ๆ ในหมวด "ยังไม่ได้จอง" คือกลุ่มที่ไม่มีใครรู้ว่าต้องโทรกลับ
            $intake->forceFill(['team_notified_at' => now()])->save();

            // ส่งหลังคอมมิต — การยกเลิกใบจองอยู่ในทรานแซกชัน ถ้ามันย้อนกลับ
            // ทีหลังเราจะได้ไม่ส่งเมลบอกเรื่องที่ไม่ได้เกิดขึ้น
            DB::afterCommit(fn () => $this->mail->sendAdminIntakeReady($intake, 'reopened'));
        }

        return $intakes->count();
    }

    /**
     * กลุ่มไหน "พร้อมให้ทีมงานหยิบ" แล้วก็บอกเลย ไม่ต้องรอให้ใครเปิดหน้าแอดมินเจอ
     *
     * เกณฑ์คือ "กรอกครบตามที่แจ้งไว้" ไม่ใช่ทุกครั้งที่มีคนกรอก — กลุ่ม 5 คน
     * ที่ทยอยกรอกคนละเวลาจะกลายเป็นเมล 5 ฉบับที่ไม่มีใครทำอะไรได้กับ 4 ฉบับแรก
     * ส่วนกลุ่มที่กรอกค้างแล้วเงียบไป มี {@see NotifyStalledIntakesJob}
     * ตามเก็บให้อีกชั้น
     */
    private function notifyTeamIfReady(?CustomerIntake $intake): void
    {
        if (! $intake || $intake->team_notified_at !== null || ! $intake->acceptsSubmissions()) {
            return;
        }

        if (! $intake->isComplete()) {
            return;
        }

        // ตีตราก่อนส่ง — สองคนกดส่งพร้อมกันแล้วได้เมลซ้ำเป็นเรื่องที่เกิดขึ้นได้จริง
        $intake->forceFill(['team_notified_at' => now()])->save();

        $this->mail->sendAdminIntakeReady($intake, 'complete');
    }

    /**
     * เขียนข้อมูลคนหนึ่งคนลงกลุ่ม — ทับแถวเดิมถ้าเป็นคนเดียวกัน
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertPerson(CustomerIntake $intake, array $data, bool $isLead): CustomerIntakePerson
    {
        $phone = $this->normalisePhone($data['phone'] ?? '');
        $person = $isLead
            ? $intake->people()->where('is_lead', true)->first()
            : $this->findPerson($intake, $phone, $data['name']);

        $person ??= new CustomerIntakePerson(['customer_intake_id' => $intake->id]);

        $person->fill([
            'customer_intake_id' => $intake->id,
            'is_lead' => $isLead || $person->is_lead,
            // จุดขึ้นรถของคนนี้ — คนละคนขึ้นคนละจุดได้ในกลุ่มเดียวกัน
            //
            // กลุ่มจอยทริปไม่มีรถให้ขึ้น ฟอร์มจึงไม่ถาม แต่ค่าที่ส่งมาจากเบราว์เซอร์
            // แก้ได้ (หรือ JS ไม่ทำงานจนช่องเดิมยังถูกส่งมา) — ตัดทิ้งตรงนี้ที่เดียว
            // ทั้งประตูลิงก์ทีมงานและลิงก์กลุ่มจะได้ผลเหมือนกันเสมอ
            'pickup_point_id' => $intake->isJoinTrip()
                ? null
                : ($data['pickup_point_id'] ?? $person->pickup_point_id),
            'title' => $data['title'] ?? null,
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'phone' => $phone ?: null,
            'email' => $data['email'] ?? null,
            'id_card' => $this->digits($data['id_card'] ?? null),
            'birth_date' => $data['birth_date'] ?? null,
            'blood_group' => ($data['blood_group'] ?? null) ?: null,
            'name_en' => filled($data['name_en'] ?? null) ? mb_strtoupper($data['name_en']) : null,
            'nationality' => $data['nationality'] ?? null,
            'passport_no' => filled($data['passport_no'] ?? null) ? mb_strtoupper($data['passport_no']) : null,
            'passport_expires_at' => $data['passport_expires_at'] ?? null,
            'emergency_contact' => $data['emergency_contact'] ?? null,
            'emergency_phone' => $this->normalisePhone($data['emergency_phone'] ?? '') ?: null,
            'allergies' => $data['allergies'] ?? null,
            'health_notes' => $data['health_notes'] ?? null,
            'halal_food' => (bool) ($data['halal_food'] ?? false),
            // ทุกครั้งที่กรอกคือการยินยอมครั้งใหม่ — เก็บครั้งล่าสุดไว้พร้อมข้อความ
            // ที่แสดงตอนนั้น ข้อมูลอ่อนไหวตาม PDPA ต้องพิสูจน์ความยินยอมย้อนหลังได้
            'consent_at' => now(),
            'consent_ip' => $data['consent_ip'] ?? null,
            'consent_text' => CustomerIntakePerson::CONSENT_TEXT,
        ])->save();

        return $person;
    }

    /**
     * คนเดิมหรือคนใหม่ — เบอร์คือตัวชี้ขาด เพราะไม่ซ้ำกันจริงในกลุ่มขนาดนี้
     * ไม่มีเบอร์ค่อยถอยไปเทียบชื่อเต็มแบบตัดช่องว่าง (บางคนไม่กรอกเบอร์)
     */
    private function findPerson(CustomerIntake $intake, string $phone, string $name): ?CustomerIntakePerson
    {
        if ($phone !== '') {
            $match = $intake->people()->where('phone', $phone)->first();
            if ($match) {
                return $match;
            }
        }

        $needle = $this->compactName($name);

        return $intake->people()->get()->first(fn (CustomerIntakePerson $p) => $this->compactName($p->name) === $needle);
    }

    private function compactName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', trim($name)) ?? '');
    }

    /** เก็บเบอร์เป็นตัวเลขล้วนเสมอ ไม่งั้น 08x-xxx-xxxx กับ 08xxxxxxxx จะไม่ตรงกัน */
    private function normalisePhone(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?? '';
    }

    private function digits(?string $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    private function mergeNote(?string $existing, ?string $incoming): ?string
    {
        if (blank($incoming)) {
            return $existing;
        }

        if (blank($existing) || str_contains($existing, trim($incoming))) {
            return blank($existing) ? trim($incoming) : $existing;
        }

        return $existing."\n".trim($incoming);
    }
}
