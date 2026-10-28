<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\ThaiDate;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ลายเซ็นบนแบบฟอร์มอุทยาน
 *
 * อุทยานให้แนบแบบฟอร์มที่เซ็นแล้วตอนลงทะเบียนออนไลน์ล่วงหน้า ผู้เดินทางจึงต้อง
 * เซ็นก่อนวันลงทะเบียน (`permit_forms.sign_due_days` วันก่อนเดินทาง) เซ็นได้จาก
 * แอป เว็บ และลิงก์ของเพื่อน (/f/) — ทุกช่องทางมาจบที่ sign() ที่เดียว
 *
 * ใครเซ็นให้ใครได้ (ตามกติกาเดียวกับ "ไปครบไหม"): คนจองเซ็นได้ทุกคนในใบจอง
 * (พ่อแม่เซ็นแทนลูก, คนจองที่กรอกให้ทั้งกลุ่ม) เพื่อนที่ผูกชื่อแล้วเซ็นได้เฉพาะ
 * ตัวเอง ลิงก์ของเพื่อนเซ็นได้เฉพาะเจ้าของลิงก์
 *
 * ลูกค้าต้องเห็นแบบฟอร์มเต็มก่อนเซ็นเสมอ (มีข้อสละสิทธิ์ฟ้องร้อง) และเก็บเวลา IP
 * ช่องทาง และบัญชีที่เซ็นไว้เป็นหลักฐาน ภาพลายเซ็นลบทิ้งหลังจบทริป ดู
 * PurgePermitSignaturesJob
 */
class PermitSignatureService
{
    public const DISK = PermitFormService::DISK;

    /** ภาพลายเซ็นใหญ่สุดที่รับ (ไบต์ของ PNG หลังถอด base64) */
    public const MAX_BYTES = 600 * 1024;

    public const CHANNEL_APP = 'app';

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_FRIEND_LINK = 'friend_link';

    public function formFor(Booking $booking): ?PermitForm
    {
        $booking->loadMissing('schedule.trip.permitForm');

        return $booking->schedule?->trip?->permitForm;
    }

    /** ทริปนี้ให้ลูกค้าเซ็นไหม — มีแบบฟอร์มและวางช่องลายเซ็นไว้ */
    public function formNeedingSignature(Booking $booking): ?PermitForm
    {
        $form = $this->formFor($booking);

        return $form?->requiresSignature() ? $form : null;
    }

    /** วันสุดท้ายที่ควรเซ็น (ก่อนทีมงานลงทะเบียนกับอุทยาน) — null = แบบฟอร์มไม่ได้กำหนด */
    public function deadline(Booking $booking, PermitForm $form): ?CarbonInterface
    {
        $departure = $booking->schedule?->departure_date;

        if (! $departure || ! $form->sign_due_days) {
            return null;
        }

        return Carbon::parse($departure->toDateString(), 'Asia/Bangkok')->subDays($form->sign_due_days);
    }

    /**
     * ยังเซ็นได้ไหม — ใบจองที่ยังไม่ยกเลิก จนถึงวันเดินทาง (เลยกำหนดแล้วก็ยังเซ็นได้
     * ทีมงานอาจยังลงทะเบียนให้ทัน แค่ขึ้นเตือนว่าเลยกำหนด)
     */
    public function isOpen(Booking $booking): bool
    {
        $departure = $booking->schedule?->departure_date;

        return in_array($booking->status, ['confirmed', 'pending'], true)
            && $departure !== null
            && $departure->toDateString() >= now('Asia/Bangkok')->toDateString();
    }

    /**
     * ผู้เดินทางที่ผู้ใช้คนนี้เซ็นให้ได้ในใบจองนี้
     *
     * @return Collection<int, BookingPassenger>
     */
    public function signablePassengers(Booking $booking, User $user): Collection
    {
        $passengers = $booking->passengers()->whereNull('not_going_at')->orderBy('id')->get();

        if ((int) $booking->user_id === (int) $user->id) {
            return $passengers;
        }

        $mine = BookingMember::where('booking_id', $booking->id)
            ->where('user_id', $user->id)
            ->where('status', BookingMember::STATUS_ACTIVE)
            ->value('passenger_id');

        return $passengers->where('id', (int) $mine)->values();
    }

    /** สถานะการเซ็นของใบจอง ตามสิทธิ์ของผู้ดู — ใช้ในแอปและเว็บ */
    public function summary(Booking $booking, User $user): ?array
    {
        $form = $this->formNeedingSignature($booking);

        if (! $form) {
            return null;
        }

        $passengers = $this->signablePassengers($booking, $user);
        $deadline = $this->deadline($booking, $form);
        $isOwner = (int) $booking->user_id === (int) $user->id;

        return [
            'form' => ['id' => $form->id, 'name' => $form->name],
            'open' => $this->isOpen($booking),
            'deadline' => $deadline?->toDateString(),
            'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            'overdue' => $deadline !== null && $deadline->toDateString() < now('Asia/Bangkok')->toDateString(),
            'viewer_role' => $isOwner ? 'owner' : 'member',
            'signed_count' => $passengers->filter(fn (BookingPassenger $p) => $p->hasSignedPermitForm($form->id))->count(),
            'total' => $passengers->count(),
            'passengers' => $passengers->map(fn (BookingPassenger $p) => [
                'id' => $p->id,
                'name' => $p->displayName(),
                'signed' => $p->hasSignedPermitForm($form->id),
                'signed_at' => $p->hasSignedPermitForm($form->id) ? $p->permit_signed_at?->toISOString() : null,
                // คนจองส่งลิงก์ให้เพื่อนเซ็นเองได้ — ลิงก์ของเพื่อนไม่มีข้อมูลส่วนตัวเต็ม
                'sign_url' => $isOwner ? url('/f/'.$p->ensurePassToken().'/permit') : null,
            ])->values()->all(),
        ];
    }

    /**
     * บันทึกลายเซ็น
     *
     * @param  string  $dataUrl  PNG แบบ data URL (`data:image/png;base64,...`) จากแผ่นเซ็นของทุกช่องทาง
     * @param  array{channel: string, ip?: string|null, user_agent?: string|null, user_id?: int|null}  $meta
     *
     * @throws \Exception ข้อความภาษาไทยสำหรับแสดงลูกค้า
     */
    public function sign(BookingPassenger $passenger, PermitForm $form, string $dataUrl, array $meta): BookingPassenger
    {
        $booking = $passenger->booking;

        if (! $this->isOpen($booking)) {
            throw new \Exception('ใบจองนี้เซ็นแบบฟอร์มไม่ได้แล้ว');
        }

        if ($passenger->not_going_at !== null) {
            throw new \Exception('คนนี้แจ้งว่าไม่ไปแล้ว ไม่ต้องเซ็นแบบฟอร์ม');
        }

        $png = $this->decode($dataUrl);
        $png = $this->trim($png);

        $path = 'permit-signatures/'.$passenger->id.'-'.Str::random(16).'.png';
        Storage::disk(self::DISK)->put($path, $png);

        $passenger->forceFill([
            'permit_signature_path' => $path,
            'permit_signed_at' => now(),
            'permit_signed_form_id' => $form->id,
            'permit_sign_meta' => [
                'channel' => $meta['channel'],
                'ip' => $meta['ip'] ?? null,
                'user_agent' => Str::limit((string) ($meta['user_agent'] ?? ''), 255, ''),
                'user_id' => $meta['user_id'] ?? null,
            ],
        ])->save();

        return $passenger;
    }

    /**
     * แจ้งคนจองครั้งแรกว่าทริปนี้ต้องเซ็นแบบฟอร์ม — ทันทีที่ยืนยันการจอง ไม่ต้องรอ
     * ช่วงเตือนใกล้กำหนด (คนที่จองล่วงหน้าเป็นเดือนจะได้ไม่มารู้ตอนใกล้วันลงทะเบียน)
     *
     * ครั้งเดียวต่อใบจอง (`permit_requested_at`) รอบที่ยังมีคนไม่ครบขั้นต่ำยังไม่แจ้ง
     * — ขอให้เซ็นตอนนี้เท่ากับบอกว่ารอบออกแน่แล้ว RemindPermitSignaturesJob มาแจ้ง
     * ให้เองเมื่อรอบครบหรือทีมงานยืนยันออกเดินทาง
     *
     * @return bool ส่งแล้วหรือไม่
     */
    public function sendFirstRequest(Booking $booking): bool
    {
        $booking->loadMissing(['schedule.trip.permitForm', 'passengers']);
        $schedule = $booking->schedule;
        $form = $this->formNeedingSignature($booking);

        if (! $form
            || $booking->status !== 'confirmed'
            || ! $booking->user_id
            || $booking->permit_requested_at !== null
            || ($booking->force_majeure_at !== null && $booking->force_majeure_resolved_at === null)
            || ! $schedule
            || $schedule->status === 'cancelled'
            || $schedule->holdsTravelMessages()
            || ! $this->isOpen($booking)) {
            return false;
        }

        $unsigned = $booking->passengers
            ->whereNull('not_going_at')
            ->reject(fn (BookingPassenger $p) => $p->hasSignedPermitForm($form->id));

        if ($unsigned->isEmpty()) {
            return false;
        }

        // บันทึกก่อนส่ง — งานถูกลองซ้ำได้ อย่าให้คนจองได้ข้อความซ้ำ และนับเป็นการเตือน
        // ของวันนี้ด้วย ใบที่จองเข้ามาในช่วงเตือนจะได้ไม่ได้สองข้อความในวันเดียว
        $booking->ensureBriefToken();
        $booking->forceFill([
            'permit_requested_at' => now(),
            'permit_reminded_at' => now(),
        ])->saveQuietly();

        $deadline = $this->deadline($booking, $form);
        $total = $booking->passengers->whereNull('not_going_at')->count();

        SmartNotification::send(
            $booking->user_id,
            'permit_sign_reminder',
            'ทริปนี้ต้องเซ็นแบบฟอร์มอุทยาน',
            ($schedule->trip?->title ?? 'ทริปของคุณ').' — อุทยานให้ผู้เดินทางทุกคนเซ็นแบบฟอร์มก่อนลงทะเบียน'
                .($deadline ? ' กรุณาเซ็นภายใน '.ThaiDate::full($deadline) : '')
                .' ข้อมูลกรอกไว้ให้แล้ว แค่อ่านและเซ็น'
                .($total > 1 ? ' (ส่งลิงก์ให้เพื่อนเซ็นเองได้)' : ''),
            [
                'booking_ref' => $booking->booking_ref,
                'route' => 'permit_form',
                'web_url' => $booking->briefUrl().'#permit',
            ],
        );

        return true;
    }

    /** ล้างลายเซ็น (แอดมิน: คนเซ็นผิดคน / ข้อมูลเปลี่ยน ต้องเซ็นใหม่) */
    public function clear(BookingPassenger $passenger): void
    {
        $passenger->forceFill([
            'permit_signature_path' => null,
            'permit_signed_at' => null,
            'permit_signed_form_id' => null,
            'permit_sign_meta' => null,
        ])->save();
    }

    public static function deleteFile(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * ถอด data URL และตรวจว่าเป็นภาพ PNG จริง ขนาดพอเหมาะ และมีเส้นอยู่จริง
     *
     * @throws \Exception
     */
    private function decode(string $dataUrl): string
    {
        if (! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', trim($dataUrl), $m)) {
            throw new \Exception('ไม่พบลายเซ็น ลองเซ็นใหม่อีกครั้ง');
        }

        $png = base64_decode(preg_replace('/\s+/', '', $m[1]), true);

        if ($png === false || strlen($png) > self::MAX_BYTES || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw new \Exception('ไฟล์ลายเซ็นไม่ถูกต้อง ลองเซ็นใหม่อีกครั้ง');
        }

        $size = @getimagesizefromstring($png);
        if (! $size || $size[0] < 20 || $size[1] < 10 || $size[0] > 3000 || $size[1] > 3000) {
            throw new \Exception('ไฟล์ลายเซ็นไม่ถูกต้อง ลองเซ็นใหม่อีกครั้ง');
        }

        return $png;
    }

    /**
     * ตัดขอบโปร่งใสรอบลายเซ็นออก — แผ่นเซ็นบนจอกว้างกว่าเส้นมาก ถ้าไม่ตัด ลายเซ็น
     * จะเล็กนิดเดียวในช่องของฟอร์ม และแผ่นเปล่า (ไม่มีเส้นเลย) ถูกปฏิเสธที่นี่
     *
     * หากรอบเส้นเอง — imagecropauto(IMG_CROP_TRANSPARENT) ไม่รู้จักความโปร่งใสแบบ
     * alpha ของภาพ truecolor จึงไม่ตัดอะไรเลย
     *
     * @throws \Exception
     */
    private function trim(string $png): string
    {
        $image = @imagecreatefromstring($png);

        if (! $image) {
            throw new \Exception('ไฟล์ลายเซ็นไม่ถูกต้อง ลองเซ็นใหม่อีกครั้ง');
        }

        $bounds = $this->inkBounds($image);

        if ($bounds === null) {
            throw new \Exception('ยังไม่ได้เซ็น วาดลายเซ็นในกรอบก่อนกดยืนยัน');
        }

        [$x0, $y0, $x1, $y1] = $bounds;
        $w = $x1 - $x0 + 1;
        $h = $y1 - $y0 + 1;

        if ($w < 8 && $h < 8) {
            throw new \Exception('ลายเซ็นสั้นเกินไป ลองเซ็นใหม่ให้ชัดขึ้น');
        }

        // เผื่อขอบนิดหน่อย ไม่ให้เส้นชิดขอบกล่องในฟอร์ม
        $pad = 6;
        $out = imagecreatetruecolor($w + $pad * 2, $h + $pad * 2);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagecopy($out, $image, $pad, $pad, $x0, $y0, $w, $h);

        ob_start();
        imagepng($out, null, 9);

        return (string) ob_get_clean();
    }

    /**
     * กรอบของส่วนที่มีหมึก (alpha ทึบพอ) — null = แผ่นเปล่า ภาพใหญ่สุ่มเป็นช่วง ๆ
     * ให้ไม่เกินราว 400k จุด เส้นปากกากว้างหลายพิกเซลอยู่แล้วจึงไม่หลุด
     *
     * @return array{0: int, 1: int, 2: int, 3: int}|null
     */
    private function inkBounds(\GdImage $image): ?array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $step = max(1, (int) floor(sqrt(($w * $h) / 400_000)));
        $x0 = $w;
        $y0 = $h;
        $x1 = -1;
        $y1 = -1;

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                // alpha 0 = ทึบ, 127 = ใส
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 100) {
                    $x0 = min($x0, $x);
                    $y0 = min($y0, $y);
                    $x1 = max($x1, $x);
                    $y1 = max($y1, $y);
                }
            }
        }

        if ($x1 < 0) {
            return null;
        }

        return [
            max(0, $x0 - $step), max(0, $y0 - $step),
            min($w - 1, $x1 + $step), min($h - 1, $y1 + $step),
        ];
    }
}
