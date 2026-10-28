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

    /** สีหมึกของข้อความที่เติม — น้ำเงินเข้ม ให้แยกออกจากตัวพิมพ์ของฟอร์มได้ */
    private const INK = '#12307a';

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

        return $this->render($form, [PermitFormFields::sampleValues()]);
    }

    private function renderPassengers(PermitForm $form, TripSchedule $schedule, Collection $passengers, int $groupSize): string
    {
        $schedule->loadMissing('trip');

        // รอบที่ยังไม่มีใครจองก็ออกฟอร์มเปล่าได้หนึ่งชุด — ช่องของทริปยังเติมให้
        $rows = $passengers->isEmpty()
            ? [PermitFormFields::values(new BookingPassenger, $schedule, $groupSize)]
            : $passengers->map(fn (BookingPassenger $p) => PermitFormFields::values($p, $schedule, $groupSize))->all();

        return $this->render($form, $rows);
    }

    /**
     * @param  array<int, array<string, string>>  $rows  ค่าของแต่ละคน (คนละชุดหน้า)
     */
    private function render(PermitForm $form, array $rows): string
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
                    $this->writeField($mpdf, $field, $values, $w, $h);
                }
            }
        }

        return $mpdf->Output('', 'S');
    }

    private function writeField(Mpdf $mpdf, array $field, array $values, float $pageW, float $pageH): void
    {
        $source = $field['source'];
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
