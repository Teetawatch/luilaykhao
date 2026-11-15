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
 * ช่องไหนออกมาเป็นคำถามอะไร อยู่ใน config/form_fill.php ทั้งหมด และแอดมินติ๊กเลือก
 * ได้ว่าจะเอาช่องไหน (`$fields` = รหัสช่องใน config, null = ค่าเริ่มต้น) ค่าแต่ละช่อง
 * มาจาก PermitFormFields::values() ชุดเดียวกับแบบฟอร์มอุทยาน (ชื่อ/นามสกุลแยกที่
 * ช่องว่างแรก, อายุ ณ วันเดินทาง, ที่อยู่ที่ลูกค้ากรอกตอนเซ็น)
 *
 * ZIP แยกโฟลเดอร์ละคน ("01-ชื่อ นามสกุล/" มี data.json ของคนนั้น + id_card.* +
 * permit.pdf) ส่งต่อให้คนอื่นช่วยลงทะเบียนเป็นรายคนได้ ส่วน data.json ชั้นบนสุด
 * เป็นทั้งรอบ ชี้ไฟล์เข้าไปในโฟลเดอร์ — autofill.js รันที่ชั้นบนสุดได้ทั้งรอบ รันใน
 * โฟลเดอร์คนเดียวได้คนเดียว หรือรวมหลายโฟลเดอร์ไว้ด้วยกันก็ได้หลายคน
 *
 * ช่องที่ไม่ได้ติ๊กไม่มี key ใน JSON เลย ส่วนช่องที่ติ๊กแต่ไม่มีข้อมูลเป็น "" / null
 * — สคริปต์จึงแยกได้ว่า "ไม่ได้เลือก" (เงียบ) กับ "ไม่มีในระบบ" (เตือนให้กรอกเอง)
 *
 * ผู้เดินทางชุดเดียวกับหน้าเอกสารของรอบ (ScheduleDocumentService::passengers):
 * ใบจองที่ยังไม่ยกเลิก ไม่รวมคนที่แจ้งว่าไม่ไป ไม่รวมสตาฟ
 */
class FormFillExportService
{
    /** ไฟล์ใน resources/form-fill/ ที่แนบไปใน ZIP ทุกครั้ง */
    public const TOOL_FILES = ['autofill.js', 'README.txt'];

    private const GROUPS = [
        'text_fields' => 'ช่องข้อความ',
        'phone_fields' => 'เบอร์โทร',
        'choice_fields' => 'ตัวเลือก',
        'extras' => 'วันเกิดและไฟล์แนบ',
    ];

    public function __construct(
        private ScheduleDocumentService $documents,
        private PermitFormService $forms,
    ) {}

    /** ช่องติ๊กของหน้าแอดมิน จัดเป็นกลุ่มตาม config */
    public function options(): array
    {
        return collect(self::GROUPS)
            ->map(fn (string $label, string $group) => [
                'key' => $group,
                'label' => $label,
                'items' => collect(config("form_fill.{$group}", []))
                    ->filter(fn ($f) => is_array($f))
                    ->map(fn (array $f, string $id) => [
                        'id' => $id,
                        'label' => (string) ($f['label'] ?? $id),
                        'keyword' => isset($f['keyword']) ? (string) $f['keyword'] : null,
                        'default' => (bool) ($f['default'] ?? false),
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $g) => $g['items'] !== [])
            ->values()
            ->all();
    }

    /**
     * รหัสช่องที่จะส่งออก — null = ค่าเริ่มต้นใน config รหัสที่ไม่รู้จักถูกทิ้ง
     *
     * @param  array<int, string>|string|null  $fields
     * @return array<int, string>
     */
    public function selection(array|string|null $fields): array
    {
        $all = collect($this->options())->flatMap(fn (array $g) => $g['items']);

        if ($fields === null) {
            return $all->where('default', true)->pluck('id')->values()->all();
        }

        $wanted = is_string($fields) ? explode(',', $fields) : $fields;
        $wanted = array_map(fn ($id) => trim((string) $id), $wanted);

        return $all->pluck('id')->filter(fn (string $id) => in_array($id, $wanted, true))->values()->all();
    }

    /** @return array<int, array<string, mixed>> แถวละคน (path ชี้เข้าโฟลเดอร์ของแต่ละคน) */
    public function rows(TripSchedule $schedule, array|string|null $fields = null): array
    {
        return array_column($this->build($schedule, $this->selection($fields)), 'root');
    }

    /** @return string path ไฟล์ ZIP ชั่วคราว ผู้เรียกต้องลบเอง */
    public function zip(TripSchedule $schedule, array|string|null $fields = null): string
    {
        $people = $this->build($schedule, $this->selection($fields), withFiles: true);

        $path = tempnam(sys_get_temp_dir(), 'form-fill-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('data.json', $this->json(array_column($people, 'root')));

        foreach ($people as $person) {
            $folder = $person['folder'].'/';
            $zip->addFromString($folder.'data.json', $this->json([$person['own']]));

            foreach ($person['files'] as $name => $bytes) {
                $zip->addFromString($folder.$name, $bytes);
            }
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
        $title = $this->safeName((string) $schedule->trip?->title, 'รอบ');

        return 'data-json-'.$title.'-'.($schedule->departure_date?->toDateString() ?? $schedule->id).'.zip';
    }

    /**
     * @param  array<int, string>  $selected
     * @return array<int, array{folder: string, own: array, root: array, files: array<string, string>}>
     */
    private function build(TripSchedule $schedule, array $selected, bool $withFiles = false): array
    {
        $schedule->loadMissing('trip.permitForm');
        $form = $schedule->trip?->permitForm;
        $passengers = $this->documents->passengers($schedule)->values();
        // ช่อง "กลุ่มคณะ ... คน" บนแบบฟอร์มนับสตาฟด้วย — ใช้ตัวเลขเดียวกับ PDF ที่โหลดจากหน้าอื่น
        $groupSize = $form ? $this->forms->groupSize($schedule) : $passengers->count();
        $on = fn (string $id) => in_array($id, $selected, true);
        $people = [];

        foreach ($passengers as $i => $passenger) {
            $n = $i + 1;
            $folder = sprintf('%02d-%s', $n, $this->safeName((string) $passenger->name, 'ผู้เดินทาง'));
            $values = $this->values($passenger, $schedule, $groupSize);
            $files = [];

            $row = [
                'id' => $n,
                'name' => trim((string) $passenger->name),
                'bookingRef' => (string) $passenger->booking?->booking_ref,
            ];

            if ($on('birth_date')) {
                $row['birthDate'] = $passenger->birth_date?->format('Y-m-d');
            }

            $row['textFields'] = $this->fields('text_fields', $selected, $values);
            // object เสมอ — array ว่างของ PHP จะออกมาเป็น [] แทน {}
            $row['phoneFields'] = (object) collect(config('form_fill.phone_fields', []))
                ->filter(fn ($f, $id) => is_array($f) && in_array($id, $selected, true) && filled($f['json_key'] ?? null))
                ->mapWithKeys(fn (array $f) => [$f['json_key'] => preg_replace('/\D/', '', (string) ($values[$f['source'] ?? ''] ?? ''))])
                ->all();
            $row['choiceFields'] = $this->fields('choice_fields', $selected, $values);

            if ($on('id_card_file')) {
                $file = $this->idCardFile($passenger, config('form_fill.id_card_documents', []), $withFiles);
                $row['idCardFilePath'] = null;
                if ($file !== null) {
                    $row['idCardFilePath'] = "./id_card.{$file['ext']}";
                    if ($withFiles) {
                        $files["id_card.{$file['ext']}"] = $file['bytes'];
                    }
                }
            }

            if ($on('permit_file')) {
                $row['permitFilePath'] = $form ? './permit.pdf' : null;
                // null = แบบฟอร์มนี้ไม่มีช่องเซ็น / ทริปไม่ได้ผูกแบบฟอร์ม
                $row['permitSigned'] = $form?->requiresSignature() ? $passenger->hasCompletedPermitForm($form) : null;
                if ($form && $withFiles) {
                    $files['permit.pdf'] = $this->forms->renderPassengerInRound($form, $passenger, $schedule, $groupSize);
                }
            }

            $people[] = [
                'folder' => $folder,
                'own' => $row,
                'root' => $this->rebase($row, "./{$folder}/"),
                'files' => $files,
            ];
        }

        return $people;
    }

    /** path ของแถวในโฟลเดอร์คน → path จากชั้นบนสุดของ ZIP */
    private function rebase(array $row, string $prefix): array
    {
        foreach (['idCardFilePath', 'permitFilePath'] as $key) {
            if (is_string($row[$key] ?? null)) {
                $row[$key] = $prefix.substr($row[$key], 2);
            }
        }

        return $row;
    }

    /** ค่าที่ config อ้างถึงได้ — ของแบบฟอร์มอุทยาน + ที่ฟอร์มออนไลน์มักถามเพิ่ม */
    private function values(BookingPassenger $passenger, TripSchedule $schedule, int $groupSize): array
    {
        $values = PermitFormFields::values($passenger, $schedule, $groupSize);
        $values['gender'] = self::genderFromTitle($values['title'] ?? '');

        return $values;
    }

    /** @return array<int, array{keyword: string, value: string, exclude?: array<int, string>}> */
    private function fields(string $group, array $selected, array $values): array
    {
        return collect(config("form_fill.{$group}", []))
            ->filter(fn ($f, $id) => is_array($f) && in_array($id, $selected, true) && trim((string) ($f['keyword'] ?? '')) !== '')
            ->map(function (array $f) use ($values) {
                $value = array_key_exists('value', $f)
                    ? (string) $f['value']
                    : trim((string) ($values[$f['source'] ?? ''] ?? ''));
                $value = (string) (($f['map'] ?? [])[$value] ?? $value);

                $field = ['keyword' => (string) $f['keyword'], 'value' => $value];
                $exclude = array_values(array_filter(array_map('strval', $f['exclude'] ?? [])));
                if ($exclude !== []) {
                    $field['exclude'] = $exclude;
                }

                return $field;
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
    private function idCardFile(BookingPassenger $passenger, array $rule, bool $withBytes): ?array
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

        $disk = Storage::disk(MediaDisk::slipDisk());

        try {
            // JSON อย่างเดียวไม่ต้องอ่านไฟล์ทั้งก้อน แค่เช็กว่ายังอยู่ — path ใน JSON ต้องตรงกับ ZIP
            $bytes = $withBytes ? $disk->get($doc->file_path) : ($disk->exists($doc->file_path) ? '' : null);
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

    private function json(array $rows): string
    {
        return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * ชื่อโฟลเดอร์/ไฟล์ที่ Windows รับได้ — ตัดอักขระต้องห้าม และจุด/ช่องว่างท้ายชื่อ
     * (Windows ตัดทิ้งเองตอนแตก ZIP ทำให้ path ใน JSON ไม่ตรงกับโฟลเดอร์จริง)
     */
    private function safeName(string $name, string $fallback): string
    {
        $clean = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name);
        $clean = preg_replace('/\s+/u', ' ', (string) $clean);
        $clean = mb_substr(trim((string) $clean), 0, 60);

        return rtrim($clean, '. ') ?: $fallback;
    }
}
