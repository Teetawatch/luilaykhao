<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\PermitForm;
use App\Models\TripSchedule;
use App\Support\PermitFormFields;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * เติมข้อมูลผู้เดินทางลงแบบฟอร์มขออนุญาตของอุทยาน — หนึ่งชุดหน้าต่อหนึ่งคน
 *
 * แบบฟอร์มเก็บเป็นภาพรายหน้า (หน้าแอดมินแปลงจาก PDF ต้นฉบับด้วย pdf.js ตอน
 * อัปโหลด) เพราะตัวอ่าน PDF ฝั่งเซิร์ฟเวอร์ที่ใช้ได้ฟรี (FPDI) เปิดไฟล์บีบอัดแบบ
 * ใหม่ไม่ได้ ซึ่งไฟล์ของหน่วยงานราชการเป็นแบบนั้นแทบทั้งหมด
 *
 * ใช้ mPDF ไม่ใช่ dompdf เพราะต้องวางสระ/วรรณยุกต์ไทยให้ถูกที่ (OTL) บนช่องแคบ ๆ
 * ข้อความยาวเกินกล่องจะถูกย่อให้พอดีกล่องเอง
 */
class PermitFormService
{
    public const DISK = 'local';

    public const MAX_PAGES = 10;

    /** สีหมึกของข้อความที่เติม — ดำเหมือนตัวพิมพ์ของฟอร์ม (เจ้าของขอ) */
    private const INK = '#000000';

    /**
     * สร้างแบบฟอร์มจากภาพรายหน้าที่หน้าแอดมินเรนเดอร์มาแล้ว
     *
     * @param  array<int, UploadedFile>  $pageImages
     * @param  array<int, array{width_mm: float, height_mm: float}>  $pageSizes
     */
    public function create(string $name, array $pageImages, array $pageSizes, ?UploadedFile $source = null, ?string $note = null): PermitForm
    {
        $form = PermitForm::create(['name' => $name, 'note' => $note, 'pages' => [], 'fields' => []]);
        $dir = "permit-forms/{$form->id}";

        $pages = [];
        foreach (array_values($pageImages) as $i => $image) {
            $ext = $image->getMimeType() === 'image/png' ? 'png' : 'jpg';
            $path = $image->storeAs($dir, 'page-'.($i + 1).'-'.Str::random(8).'.'.$ext, self::DISK);
            $size = $pageSizes[$i] ?? null;

            $pages[] = [
                'path' => $path,
                'width_mm' => round((float) ($size['width_mm'] ?? 210), 2),
                'height_mm' => round((float) ($size['height_mm'] ?? 297), 2),
            ];
        }

        $form->pages = $pages;

        if ($source) {
            $form->source_path = $source->storeAs($dir, 'source-'.Str::random(8).'.pdf', self::DISK);
            $form->source_name = Str::limit($source->getClientOriginalName(), 250, '');
        }

        $form->save();

        return $form;
    }

    public function delete(PermitForm $form): void
    {
        Storage::disk(self::DISK)->deleteDirectory("permit-forms/{$form->id}");
        $form->delete();
    }

    /**
     * ทำความสะอาดช่องที่หน้าแอดมินส่งมา — ตำแหน่งบีบไว้ในหน้า, key ต้องอยู่ในรายการ
     */
    public function normalizeFields(array $fields, PermitForm $form): array
    {
        $pageCount = count($form->pages ?? []);
        $keys = PermitFormFields::keys();
        $clamp = fn ($v, float $min, float $max) => max($min, min($max, (float) $v));

        return collect($fields)
            ->filter(fn ($f) => is_array($f) && in_array($f['source'] ?? null, $keys, true))
            ->filter(fn ($f) => (int) ($f['page'] ?? 0) < $pageCount)
            ->map(function (array $f) use ($clamp) {
                $x = $clamp($f['x'] ?? 0, 0, 0.99);
                $y = $clamp($f['y'] ?? 0, 0, 0.99);

                return array_filter([
                    'id' => Str::limit((string) ($f['id'] ?? Str::random(8)), 32, ''),
                    'page' => max(0, (int) ($f['page'] ?? 0)),
                    'source' => $f['source'],
                    'x' => round($x, 5),
                    'y' => round($y, 5),
                    'w' => round($clamp($f['w'] ?? 0.2, 0.01, 1 - $x), 5),
                    'h' => round($clamp($f['h'] ?? 0.02, 0.005, 1 - $y), 5),
                    'size' => round($clamp($f['size'] ?? 11, 5, 36), 1),
                    'align' => in_array($f['align'] ?? null, ['left', 'center', 'right'], true) ? $f['align'] : 'left',
                    'text' => $f['source'] === PermitFormFields::STATIC
                        ? Str::limit(trim((string) ($f['text'] ?? '')), 300, '')
                        : null,
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    /**
     * ผู้เดินทางที่ต้องยื่นในรอบนี้ — ใบจองที่ยังไม่ยกเลิก ไม่รวมคนที่แจ้งว่าไม่ไปแล้ว
     * (ชุดเดียวกับใบรายชื่อผู้โดยสาร)
     */
    public function passengersFor(TripSchedule $schedule): Collection
    {
        return BookingPassenger::query()
            ->whereNull('not_going_at')
            ->whereHas('booking', fn ($q) => $q->where('schedule_id', $schedule->id)
                ->whereIn('status', ['confirmed', 'pending']))
            ->with('booking.user')
            ->orderBy('booking_id')
            ->orderBy('id')
            ->get();
    }

    /** PDF ทั้งรอบ */
    public function renderForSchedule(PermitForm $form, TripSchedule $schedule): string
    {
        $passengers = $this->passengersFor($schedule);

        return $this->renderPassengers($form, $schedule, $passengers, $passengers->count());
    }

    /**
     * ZIP ไฟล์ละคน — ระบบลงทะเบียนของอุทยานให้แนบแบบฟอร์มทีละคน ชื่อไฟล์ขึ้นต้นด้วย
     * ลำดับและบอกไว้ว่าใครยังไม่เซ็น จะได้ไม่เผลอแนบใบที่ไม่มีลายเซ็น
     *
     * @return string path ของไฟล์ ZIP ชั่วคราว (ผู้เรียกต้องลบเอง)
     */
    public function zipForSchedule(PermitForm $form, TripSchedule $schedule): string
    {
        $schedule->loadMissing('trip');
        $passengers = $this->passengersFor($schedule);
        $needsSignature = $form->requiresSignature();

        $path = tempnam(sys_get_temp_dir(), 'permit-').'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($passengers->values() as $i => $passenger) {
            $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $passenger->displayName()) ?: 'ผู้เดินทาง');
            $unsigned = $needsSignature && ! $passenger->hasSignedPermitForm($form->id) ? ' (ยังไม่เซ็น)' : '';
            $row = $this->rowFor($form, $passenger, $schedule, $passengers->count());

            $zip->addFromString(sprintf('%02d-%s%s.pdf', $i + 1, $name, $unsigned), $this->render($form, [$row]));
        }

        if ($passengers->isEmpty()) {
            $zip->addFromString('แบบฟอร์มเปล่า.pdf', $this->renderForSchedule($form, $schedule));
        }

        $zip->close();

        return $path;
    }

    /** PDF ของคนเดียว — ลูกค้าโหลดของตัวเองในแอป/เว็บ */
    public function renderForPassenger(PermitForm $form, BookingPassenger $passenger): string
    {
        $schedule = $passenger->booking->schedule;

        return $this->renderPassengers($form, $schedule, collect([$passenger]), $this->passengersFor($schedule)->count());
    }

    /**
     * แบบฟอร์มของคนหนึ่งสำหรับวาดบนจอก่อนเซ็น (เว็บ/แอป/ลิงก์ของเพื่อน) — ภาพหน้า
     * เปล่า + ข้อความของแต่ละช่องในตำแหน่งเดียวกับใน PDF
     *
     * @param  bool  $mask  ปิดข้อมูลส่วนตัวไว้บางส่วน — ใช้กับหน้าที่เปิดด้วยลิงก์อย่างเดียว
     */
    public function preview(PermitForm $form, BookingPassenger $passenger, bool $mask = false): array
    {
        $schedule = $passenger->booking->schedule->loadMissing('trip');
        $values = $this->rowFor($form, $passenger, $schedule, $this->passengersFor($schedule)->count());
        $expires = now()->addHours(2);

        return [
            'pages' => collect($form->pages)->map(fn (array $p, int $i) => [
                'url' => URL::temporarySignedRoute('public.permit-form.page', $expires, ['form' => $form->id, 'page' => $i + 1]),
                'width_mm' => $p['width_mm'],
                'height_mm' => $p['height_mm'],
            ])->values()->all(),
            'fields' => collect($form->fields ?? [])->map(function (array $f) use ($values, $mask) {
                $source = $f['source'];
                $type = PermitFormFields::type($source);
                $value = match (true) {
                    $source === PermitFormFields::STATIC => (string) ($f['text'] ?? ''),
                    $type === PermitFormFields::TYPE_SIGNATURE => '',
                    default => (string) ($values[$source] ?? ''),
                };

                if ($mask && in_array($source, PermitFormFields::SENSITIVE, true)) {
                    $value = PermitFormFields::mask($value);
                }

                return [
                    'page' => (int) $f['page'],
                    'type' => $type,
                    'x' => $f['x'], 'y' => $f['y'], 'w' => $f['w'], 'h' => $f['h'],
                    'size' => $f['size'] ?? 11,
                    'align' => $f['align'] ?? 'left',
                    'value' => $type === PermitFormFields::TYPE_CHECK ? ($value !== '' ? '✓' : '') : $value,
                ];
            })->filter(fn (array $f) => $f['value'] !== '' || $f['type'] === PermitFormFields::TYPE_SIGNATURE)
                ->values()->all(),
        ];
    }

    /** PDF เฉพาะคนในใบจองเดียว — ส่งให้ลูกค้ากรอกช่องที่เหลือ */
    public function renderForBooking(PermitForm $form, Booking $booking): string
    {
        $schedule = $booking->schedule;

        $passengers = $booking->passengers()
            ->whereNull('not_going_at')
            ->with('booking.user')
            ->orderBy('id')
            ->get();

        return $this->renderPassengers($form, $schedule, $passengers, $this->passengersFor($schedule)->count());
    }

    /** ตัวอย่างด้วยข้อมูลสมมติ — ใช้ตรวจตำแหน่งช่องก่อนใช้จริง */
    public function renderSample(PermitForm $form, ?array $fields = null): string
    {
        if ($fields !== null) {
            $form = (clone $form)->forceFill(['fields' => $fields]);
        }

        return $this->render($form, [PermitFormFields::sampleValues()], sample: true);
    }

    private function renderPassengers(PermitForm $form, TripSchedule $schedule, Collection $passengers, int $groupSize): string
    {
        $schedule->loadMissing('trip');

        // รอบที่ยังไม่มีใครจองก็ออกฟอร์มเปล่าได้หนึ่งชุด — ช่องของทริปยังเติมให้
        $rows = $passengers->isEmpty()
            ? [PermitFormFields::values(new BookingPassenger, $schedule, $groupSize)]
            : $passengers->map(fn (BookingPassenger $p) => $this->rowFor($form, $p, $schedule, $groupSize))->all();

        return $this->render($form, $rows);
    }

    /** ค่าของทุกช่อง + ลายเซ็น (เฉพาะคนที่เซ็นแบบฟอร์มฉบับนี้แล้ว) */
    private function rowFor(PermitForm $form, BookingPassenger $passenger, TripSchedule $schedule, int $groupSize): array
    {
        $values = PermitFormFields::values($passenger, $schedule, $groupSize);

        if ($passenger->hasSignedPermitForm($form->id)
            && Storage::disk(self::DISK)->exists($passenger->permit_signature_path)) {
            $values[PermitFormFields::SIGNATURE] = Storage::disk(self::DISK)->path($passenger->permit_signature_path);
        }

        return $values;
    }

    /**
     * @param  array<int, array<string, string>>  $rows  ค่าของแต่ละคน (คนละชุดหน้า)
     */
    private function render(PermitForm $form, array $rows, bool $sample = false): string
    {
        $mpdf = $this->makeMpdf();
        $disk = Storage::disk(self::DISK);
        $fieldsByPage = collect($form->fields ?? [])->groupBy('page');

        foreach ($rows as $values) {
            foreach ($form->pages as $index => $page) {
                $w = (float) $page['width_mm'];
                $h = (float) $page['height_mm'];

                $mpdf->AddPageByArray([
                    'orientation' => 'P',
                    'sheet-size' => [$w, $h],
                    'margin-left' => 0, 'margin-right' => 0,
                    'margin-top' => 0, 'margin-bottom' => 0,
                    'margin-header' => 0, 'margin-footer' => 0,
                ]);
                $mpdf->Image($disk->path($page['path']), 0, 0, $w, $h, '', '', true, false);

                foreach ($fieldsByPage->get($index, []) as $field) {
                    $this->writeField($mpdf, $field, $values, $w, $h, $sample);
                }
            }
        }

        return $mpdf->Output('', 'S');
    }

    private function writeField(Mpdf $mpdf, array $field, array $values, float $pageW, float $pageH, bool $sample = false): void
    {
        $source = $field['source'];

        if ($source === PermitFormFields::SIGNATURE) {
            $this->writeSignature($mpdf, $field, $values[$source] ?? '', $pageW, $pageH, $sample);

            return;
        }

        $value = $source === PermitFormFields::STATIC
            ? (string) ($field['text'] ?? '')
            : (string) ($values[$source] ?? '');

        if ($value === '') {
            return;
        }

        $isCheck = PermitFormFields::type($source) === PermitFormFields::TYPE_CHECK;
        $text = $isCheck ? '✓' : e($value);
        $font = $isCheck ? 'dejavusans' : 'sarabun';
        $size = (float) ($field['size'] ?? 11);
        $align = $field['align'] ?? 'left';

        $html = '<div style="font-family:'.$font.';font-size:'.$size.'pt;line-height:1.15;color:'.self::INK
            .';text-align:'.$align.';white-space:nowrap">'.$text.'</div>';

        $mpdf->WriteFixedPosHTML(
            $html,
            (float) $field['x'] * $pageW,
            (float) $field['y'] * $pageH,
            (float) $field['w'] * $pageW,
            (float) $field['h'] * $pageH,
            'auto',
        );
    }

    /**
     * วางภาพลายเซ็นให้พอดีกล่องโดยไม่บิดสัดส่วน — ชิดล่าง (ลายเซ็นนั่งบนเส้นประ)
     * และจัดแนวนอนตามที่ตั้งไว้ในช่อง
     */
    private function writeSignature(Mpdf $mpdf, array $field, string $path, float $pageW, float $pageH, bool $sample): void
    {
        $boxX = (float) $field['x'] * $pageW;
        $boxY = (float) $field['y'] * $pageH;
        $boxW = (float) $field['w'] * $pageW;
        $boxH = (float) $field['h'] * $pageH;

        if ($path === '') {
            if ($sample) {
                $mpdf->WriteFixedPosHTML(
                    '<div style="font-family:sarabun;font-size:10pt;color:#9ca3af;text-align:center">( ลายเซ็นของผู้เดินทาง )</div>',
                    $boxX, $boxY + max(0, $boxH / 2 - 2.5), $boxW, $boxH, 'auto',
                );
            }

            return;
        }

        $size = @getimagesize($path);
        if (! $size || $size[0] < 1 || $size[1] < 1) {
            return;
        }

        $scale = min($boxW / $size[0], $boxH / $size[1]);
        $w = $size[0] * $scale;
        $h = $size[1] * $scale;
        $x = match ($field['align'] ?? 'center') {
            'left' => $boxX,
            'right' => $boxX + $boxW - $w,
            default => $boxX + ($boxW - $w) / 2,
        };

        $mpdf->Image($path, $x, $boxY + $boxH - $h, $w, $h, 'png', '', true, false);
    }

    private function makeMpdf(): Mpdf
    {
        $tempDir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($tempDir);

        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tempDir,
            'fontDir' => [...$fontDirs, storage_path('fonts')],
            'fontdata' => $fontData + [
                'sarabun' => ['R' => 'Sarabun-Regular.ttf', 'useOTL' => 0xFF],
            ],
            'default_font' => 'sarabun',
            // ตัดคำไทยด้วยพจนานุกรมจะแทรก U+200B ระหว่างพยางค์ ซึ่ง Sarabun ไม่มีรูป
            // กลายเป็นกล่องสี่เหลี่ยม — ช่องในฟอร์มเป็นบรรทัดเดียวอยู่แล้ว ไม่ต้องตัด
            'useDictionaryLBR' => false,
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
        ]);
        $mpdf->SetAutoPageBreak(false);
        $mpdf->SetTitle('แบบคำขออนุญาตเข้าพื้นที่');

        return $mpdf;
    }
}
