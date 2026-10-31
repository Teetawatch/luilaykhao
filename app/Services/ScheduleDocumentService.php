<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\TripSchedule;
use App\Support\MediaDisk;
use App\Support\ThaiDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * เอกสารทั้งรอบในที่เดียว — แบบฟอร์มอุทยาน (ถ้าทริปผูกไว้) + ไฟล์ที่ลูกค้าแนบมา
 *
 * ทีมงานลงทะเบียนกับอุทยาน/ยื่นด่านทีละคน เดิมต้องโหลดแบบฟอร์มจากหน้าหนึ่ง แล้วไล่
 * เปิดใบจองทีละใบหาไฟล์แนบ บริการนี้รวมสองอย่างเป็นแถวละคน และแพ็กเป็น ZIP ได้ทั้ง
 * รอบ (โฟลเดอร์ละคน) หรือเฉพาะคนเดียว
 *
 * ผู้เดินทางชุดเดียวกับแบบฟอร์มอุทยาน: ใบจองที่ยังไม่ยกเลิก ไม่รวมคนที่แจ้งว่าไม่ไป
 */
class ScheduleDocumentService
{
    public function __construct(
        private BookingDocumentService $documents,
        private PermitFormService $forms,
        private PermitSignatureService $signatures,
    ) {}

    /** รายคน: เซ็นแบบฟอร์มหรือยัง + เอกสารแนบแต่ละรายการ */
    public function summary(TripSchedule $schedule): array
    {
        $schedule->loadMissing('trip.permitForm');
        $form = $schedule->trip?->permitForm;
        $needsSignature = (bool) $form?->requiresSignature();
        $passengers = $this->passengers($schedule);

        $rows = $passengers->map(function (BookingPassenger $p) use ($form, $needsSignature) {
            $booking = $p->booking;
            $signed = $needsSignature ? $p->hasCompletedPermitForm($form) : null;
            $documents = $this->documentRows($booking, $p);

            return [
                'passenger_id' => $p->id,
                'booking_ref' => $booking->booking_ref,
                'booking_status' => $booking->status,
                'title' => $p->title,
                'name' => $p->name,
                'nickname' => $p->nickname,
                'customer_name' => $booking->user?->name,
                'permit_signed' => $signed,
                'permit_signed_at' => $signed ? $p->permit_signed_at?->toISOString() : null,
                // ลิงก์ /f/…/permit เปิดได้โดยไม่ต้องล็อกอิน — ให้เฉพาะคนที่ยังต้องเซ็น
                'sign_url' => $signed === false ? $this->signatures->signUrl($p) : null,
                'share_text' => $signed === false ? $this->signatures->shareText($p, $booking, $form) : null,
                'documents' => $documents,
                'file_count' => collect($documents)->sum(fn (array $d) => count($d['files'])),
                'missing_labels' => collect($documents)->where('is_missing', true)->pluck('label')->values()->all(),
            ];
        })->values();

        $deadline = $form?->sign_due_days && $schedule->departure_date
            ? $schedule->departure_date->copy()->subDays($form->sign_due_days)
            : null;

        return [
            'schedule_id' => $schedule->id,
            'trip_title' => $schedule->trip?->title,
            'departure_date' => $schedule->departure_date?->format('Y-m-d'),
            'permit_form' => $form ? [
                'id' => $form->id,
                'name' => $form->name,
                'requires_signature' => $needsSignature,
                'sign_due_days' => $form->sign_due_days,
                'deadline_label' => $deadline ? ThaiDate::full($deadline) : null,
            ] : null,
            'passengers' => $rows,
            'total' => $rows->count(),
            'signed_count' => $rows->where('permit_signed', true)->count(),
            'unsigned_count' => $rows->where('permit_signed', false)->count(),
            'missing_documents_count' => $rows->filter(fn (array $r) => $r['missing_labels'] !== [])->count(),
            'file_count' => $rows->sum('file_count'),
        ];
    }

    /**
     * ZIP เอกสาร — ทั้งรอบแยกโฟลเดอร์ละคน หรือ `$passengerId` = เฉพาะคนนั้น (ไฟล์อยู่ชั้นบนสุด)
     *
     * @return string|null path ของไฟล์ ZIP ชั่วคราว (ผู้เรียกต้องลบเอง) — null = ไม่มีอะไรให้โหลด
     */
    public function zip(TripSchedule $schedule, ?int $passengerId = null): ?string
    {
        $schedule->loadMissing('trip.permitForm');
        $form = $schedule->trip?->permitForm;
        $passengers = $this->passengers($schedule);
        $groupSize = $passengers->count();

        if ($passengerId !== null) {
            $passengers = $passengers->where('id', $passengerId)->values();
        }

        // ใช้ไฟล์ที่ tempnam สร้างตรง ๆ (ชื่อไฟล์ตอนดาวน์โหลดตั้งแยกอยู่แล้ว) — ต่อ .zip จะทิ้งไฟล์เปล่าค้าง
        $path = tempnam(sys_get_temp_dir(), 'round-docs-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $added = 0;

        foreach ($passengers->values() as $i => $passenger) {
            $folder = $passengerId !== null ? '' : sprintf('%02d-%s/', $i + 1, $this->safeName($this->fullName($passenger)));

            foreach ($this->filesFor($passenger, $schedule, $form, $groupSize) as $name => $bytes) {
                $zip->addFromString($folder.$name, $bytes);
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($path);

            return null;
        }

        return $path;
    }

    /** ชื่อไฟล์ ZIP ที่แอดมินจะเห็น */
    public function zipName(TripSchedule $schedule, ?BookingPassenger $passenger = null): string
    {
        $date = $schedule->departure_date?->toDateString() ?? 'round';

        return $passenger
            ? 'เอกสาร-'.$this->safeName($this->fullName($passenger)).'-'.$date.'.zip'
            : 'เอกสาร-'.$this->safeName((string) $schedule->trip?->title).'-'.$date.'.zip';
    }

    /** @return Collection<int, BookingPassenger> */
    public function passengers(TripSchedule $schedule): Collection
    {
        $passengers = BookingPassenger::query()
            ->whereNull('not_going_at')
            ->whereHas('booking', fn ($q) => $q->where('schedule_id', $schedule->id)
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES))
            ->with(['booking.user', 'booking.passengers', 'booking.documents'])
            ->orderBy('booking_id')
            ->orderBy('id')
            ->get();

        // requirementsFor/shareText อ่านทริปผ่าน booking->schedule — ใช้ก้อนที่โหลดไว้แล้ว
        $passengers->each(fn (BookingPassenger $p) => $p->booking->setRelation('schedule', $schedule));

        return $passengers;
    }

    private function documentRows(Booking $booking, BookingPassenger $passenger): array
    {
        $files = $booking->documents->where('booking_passenger_id', $passenger->id);

        return collect($this->documents->requirementsFor($booking))
            ->filter(fn (array $r) => $this->documents->appliesTo($r, $booking, $passenger))
            ->map(function (array $r) use ($files) {
                $mine = $files->where('requirement_key', $r['key'])
                    ->map(fn (BookingDocument $doc) => $this->documents->filePayload($doc))
                    ->values()
                    ->all();

                return [
                    'key' => $r['key'],
                    'label' => $r['label'],
                    'required' => (bool) $r['required'],
                    'files' => $mine,
                    'is_missing' => $r['required'] && $mine === [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * ไฟล์ทั้งหมดของคนหนึ่ง ชื่อไฟล์ → เนื้อไฟล์ — แบบฟอร์มอุทยานก่อน แล้วตามด้วยไฟล์แนบ
     * ตามชื่อเอกสาร (หลายไฟล์ในรายการเดียวกันต่อท้ายด้วยเลข)
     *
     * @return array<string, string>
     */
    private function filesFor(BookingPassenger $passenger, TripSchedule $schedule, ?PermitForm $form, int $groupSize): array
    {
        $files = [];

        if ($form) {
            // ชื่อไฟล์บอกไว้ว่าใครยังไม่เซ็น จะได้ไม่เผลอแนบใบที่ไม่มีลายเซ็นให้อุทยาน
            $unsigned = $form->requiresSignature() && ! $passenger->hasCompletedPermitForm($form) ? ' (ยังไม่เซ็น)' : '';
            $files['แบบฟอร์มอุทยาน-'.$this->safeName($form->name).$unsigned.'.pdf']
                = $this->forms->renderPassengerInRound($form, $passenger, $schedule, $groupSize);
        }

        $disk = Storage::disk(MediaDisk::slipDisk());

        $passenger->booking->documents
            ->where('booking_passenger_id', $passenger->id)
            ->sortBy('id')
            ->groupBy('requirement_key')
            ->each(function (Collection $docs) use (&$files, $disk) {
                foreach ($docs->values() as $n => $doc) {
                    try {
                        $bytes = $disk->get($doc->file_path);
                    } catch (\Throwable) {
                        $bytes = null;
                    }

                    // ไฟล์หายจากดิสก์ (ถูกล้างไปแล้ว) — ข้ามไป ไม่ทำให้ทั้ง ZIP พัง
                    if ($bytes === null) {
                        continue;
                    }

                    $base = $this->safeName($doc->label ?: 'เอกสาร').($docs->count() > 1 ? '-'.($n + 1) : '');
                    $files[$this->unique($files, $base, $this->extension($doc))] = $bytes;
                }
            });

        return $files;
    }

    private function unique(array $files, string $base, string $ext): string
    {
        $name = "{$base}.{$ext}";
        for ($i = 2; isset($files[$name]); $i++) {
            $name = "{$base} ({$i}).{$ext}";
        }

        return $name;
    }

    private function extension(BookingDocument $doc): string
    {
        $ext = strtolower(pathinfo((string) $doc->original_name, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{2,5}$/', $ext)) {
            return $ext;
        }

        return match ($doc->mime_type) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic', 'image/heif' => 'heic',
            default => strtolower(pathinfo((string) $doc->file_path, PATHINFO_EXTENSION)) ?: 'jpg',
        };
    }

    private function fullName(BookingPassenger $passenger): string
    {
        return trim((string) $passenger->name) ?: $passenger->displayName();
    }

    private function safeName(string $name): string
    {
        return trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $name)) ?: 'ผู้เดินทาง';
    }
}
