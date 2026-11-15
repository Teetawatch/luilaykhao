<?php

namespace App\Services;

use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\TripSchedule;
use App\Support\MediaDisk;
use App\Support\PermitFormFields;
use Illuminate\Support\Facades\Storage;

/**
 * data.json ให้สคริปต์ Playwright กรอกแบบฟอร์มออนไลน์ (เช่น Google Form ลงทะเบียน
 * อุทยาน) ทีละคน แทนการพิมพ์จากใบรายชื่อ
 *
 * ช่องไหนออกมาเป็นคำถามอะไร อยู่ใน config/form_fill.php ทั้งหมด — ฟอร์มปลายทาง
 * เปลี่ยนคำถามก็แก้แค่ config โครงของ JSON และตัวสคริปต์เหมือนเดิม ค่าแต่ละช่อง
 * มาจาก PermitFormFields::values() ชุดเดียวกับแบบฟอร์มอุทยาน (ชื่อ/นามสกุลแยกที่
 * ช่องว่างแรก, อายุ ณ วันเดินทาง, ที่อยู่ที่ลูกค้ากรอกตอนเซ็น)
 *
 * ผู้เดินทางชุดเดียวกับหน้าเอกสารของรอบ (ScheduleDocumentService::passengers):
 * ใบจองที่ยังไม่ยกเลิก ไม่รวมคนที่แจ้งว่าไม่ไป ไม่รวมสตาฟ
 *
 * ทริปที่ผูกแบบฟอร์มอุทยาน ZIP มี permit_N.pdf ของแต่ละคนด้วย (อุทยานให้แนบใบที่
 * เซ็นแล้วตอนลงทะเบียนออนไลน์) — `permitSigned` บอกสคริปต์ว่าใบไหนยังไม่มีลายเซ็น
 */
class FormFillExportService
{
    /** ไฟล์ใน resources/form-fill/ ที่แนบไปใน ZIP ทุกครั้ง */
    public const TOOL_FILES = ['autofill.js', 'README.txt'];

    public function __construct(
        private ScheduleDocumentService $documents,
        private PermitFormService $forms,
    ) {}

    /** @return array<int, array<string, mixed>> แถวละคน ตามลำดับใบจอง */
    public function rows(TripSchedule $schedule): array
    {
        return $this->build($schedule)['rows'];
    }

    /**
     * ZIP: data.json + id_card_N.* + permit_N.pdf + autofill.js/README.txt ข้างกัน (path ใน JSON เป็น ./id_card_N.* จึงแตก
     * ZIP แล้วรันสคริปต์ในโฟลเดอร์นั้นได้ทันที)
     *
     * @return string path ไฟล์ชั่วคราว ผู้เรียกต้องลบเอง
     */
    public function zip(TripSchedule $schedule): string
    {
        ['rows' => $rows, 'files' => $files] = $this->build($schedule, withPermitPdfs: true);

        $path = tempnam(sys_get_temp_dir(), 'form-fill-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('data.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        // สคริปต์กรอกฟอร์ม + วิธีใช้ ฉบับล่าสุดไปกับข้อมูลทุกครั้ง — แก้ที่ resources/form-fill/
        foreach (self::TOOL_FILES as $name) {
            $tool = resource_path('form-fill/'.$name);
            if (is_file($tool)) {
                $zip->addFile($tool, $name);
            }
        }

        $zip->close();

        return $path;
    }

    public function zipName(TripSchedule $schedule): string
    {
        $title = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string) $schedule->trip?->title)) ?: 'รอบ';

        return 'data-json-'.$title.'-'.($schedule->departure_date?->toDateString() ?? $schedule->id).'.zip';
    }

    /** @return array{rows: array<int, array<string, mixed>>, files: array<string, string>} */
    private function build(TripSchedule $schedule, bool $withPermitPdfs = false): array
    {
        $schedule->loadMissing('trip.permitForm');
        $form = $schedule->trip?->permitForm;
        $passengers = $this->documents->passengers($schedule)->values();
        // ช่อง "กลุ่มคณะ ... คน" บนแบบฟอร์มนับสตาฟด้วย — ใช้ตัวเลขเดียวกับ PDF ที่โหลดจากหน้าอื่น
        $groupSize = $form ? $this->forms->groupSize($schedule) : $passengers->count();
        $config = config('form_fill');
        $rows = [];
        $files = [];

        foreach ($passengers as $i => $passenger) {
            $n = $i + 1;
            $values = $this->values($passenger, $schedule, $groupSize);

            $file = $this->idCardFile($passenger, $config['id_card_documents'] ?? []);
            $fileName = null;
            if ($file !== null) {
                $fileName = "id_card_{$n}.{$file['ext']}";
                $files[$fileName] = $file['bytes'];
            }

            $permitName = null;
            if ($form) {
                $permitName = "permit_{$n}.pdf";
                // JSON อย่างเดียว (?format=json) ไม่ต้องเรนเดอร์ PDF — บอกแค่ชื่อไฟล์ที่จะอยู่ใน ZIP
                if ($withPermitPdfs) {
                    $files[$permitName] = $this->forms->renderPassengerInRound($form, $passenger, $schedule, $groupSize);
                }
            }

            $rows[] = [
                'id' => $n,
                'bookingRef' => (string) $passenger->booking?->booking_ref,
                'birthDate' => $values['birth_date_iso'] ?: null,
                'textFields' => $this->fields($config['text_fields'] ?? [], $values),
                'phoneFields' => collect($config['phone_fields'] ?? [])
                    ->map(fn (string $source) => preg_replace('/\D/', '', (string) ($values[$source] ?? '')))
                    ->all(),
                'choiceFields' => $this->fields($config['choice_fields'] ?? [], $values),
                'idCardFilePath' => $fileName ? './'.$fileName : null,
                'permitFilePath' => $permitName ? './'.$permitName : null,
                // null = แบบฟอร์มนี้ไม่มีช่องเซ็น / ทริปไม่ได้ผูกแบบฟอร์ม
                'permitSigned' => $form?->requiresSignature() ? $passenger->hasCompletedPermitForm($form) : null,
            ];
        }

        return ['rows' => $rows, 'files' => $files];
    }

    /** ค่าที่ config อ้างถึงได้ — ของแบบฟอร์มอุทยาน + ที่ฟอร์มออนไลน์มักถามเพิ่ม */
    private function values(BookingPassenger $passenger, TripSchedule $schedule, int $groupSize): array
    {
        $values = PermitFormFields::values($passenger, $schedule, $groupSize);
        $values['gender'] = self::genderFromTitle($values['title'] ?? '');
        $values['birth_date_iso'] = $passenger->birth_date?->format('Y-m-d') ?? '';

        return $values;
    }

    /** @return array<int, array{keyword: string, value: string}> */
    private function fields(array $spec, array $values): array
    {
        return collect($spec)
            ->filter(fn ($f) => is_array($f) && trim((string) ($f['keyword'] ?? '')) !== '')
            ->map(function (array $f) use ($values) {
                $value = array_key_exists('value', $f)
                    ? (string) $f['value']
                    : trim((string) ($values[$f['source'] ?? ''] ?? ''));
                $value = (string) (($f['map'] ?? [])[$value] ?? $value);

                return ['keyword' => (string) $f['keyword'], 'value' => $value];
            })
            ->values()
            ->all();
    }

    public static function genderFromTitle(string $title): string
    {
        $t = mb_strtolower(trim(str_replace('.', '', $title)));

        return match (true) {
            in_array($t, ['นาย', 'ดช', 'เด็กชาย', 'mr', 'master'], true) => 'ชาย',
            in_array($t, ['นาง', 'นางสาว', 'นส', 'ดญ', 'เด็กหญิง', 'mrs', 'ms', 'miss'], true) => 'หญิง',
            default => '',
        };
    }

    /** @return array{bytes: string, ext: string}|null รูปแรกของคนนี้ที่เป็นบัตรประชาชน */
    private function idCardFile(BookingPassenger $passenger, array $rule): ?array
    {
        $docs = $passenger->booking->documents
            ->where('booking_passenger_id', $passenger->id)
            ->filter(fn (BookingDocument $d) => str_starts_with((string) $d->mime_type, 'image/') || $d->mime_type === 'application/pdf')
            ->sortBy('id');

        $keys = $rule['keys'] ?? [];
        $words = $rule['label_contains'] ?? [];

        $doc = $docs->first(fn (BookingDocument $d) => in_array($d->requirement_key, $keys, true))
            ?? $docs->first(fn (BookingDocument $d) => collect($words)->contains(fn ($w) => $w !== '' && str_contains((string) $d->label, $w)));

        if (! $doc) {
            return null;
        }

        try {
            $bytes = Storage::disk(MediaDisk::slipDisk())->get($doc->file_path);
        } catch (\Throwable) {
            $bytes = null;
        }

        // ไฟล์ถูกล้างไปแล้ว — ให้สคริปต์ข้ามช่องอัปโหลดของคนนี้ ไม่ทำให้ทั้งไฟล์พัง
        if ($bytes === null) {
            return null;
        }

        $ext = strtolower(pathinfo((string) ($doc->original_name ?: $doc->file_path), PATHINFO_EXTENSION));

        return ['bytes' => $bytes, 'ext' => preg_match('/^[a-z0-9]{2,5}$/', $ext) ? $ext : 'jpg'];
    }
}
