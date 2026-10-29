<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\CustomerIntakePerson;
use App\Models\Trip;
use App\Support\MediaDisk;
use App\Support\TripDocumentRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * เอกสารแนบของการจอง — เก็บไฟล์ ลบไฟล์ และสรุปว่าใครยังขาดอะไร
 *
 * ข้อกำหนดอยู่บนทริป (แอดมินตั้งเอง) ส่วนไฟล์อยู่กับผู้เดินทางรายคน บริการนี้
 * คือที่เดียวที่จับสองอย่างมาต่อกัน — ทุกหน้าจอจึงเห็น "ครบ/ไม่ครบ" ตรงกัน
 */
class BookingDocumentService
{
    /** ชนิดไฟล์ที่รับ — รูปถ่ายเอกสาร หรือไฟล์ PDF ที่หน่วยงานออกให้ */
    public const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'];

    /** ขนาดสูงสุดต่อไฟล์ (KB) — 10 MB พอสำหรับรูปจากมือถือรุ่นใหม่ */
    public const MAX_SIZE_KB = 10240;

    /** จำนวนไฟล์สูงสุดต่อผู้เดินทางหนึ่งคนต่อเอกสารหนึ่งชิ้น */
    public const MAX_FILES_PER_SLOT = 5;

    /**
     * การจองนี้ขอเอกสารอะไรบ้าง (จัดระเบียบแล้ว) — ของที่แอดมินตั้งบนทริป บวก
     * บัตรประชาชนหน้า/หลังเมื่อมีคนในใบจองเลือกให้ทีมงานทำ Border Pass
     *
     * แต่ละรายการมี `for` บอกว่าใช้กับใคร ดู [appliesTo]
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool, for: string}>
     */
    public function requirementsFor(Booking $booking): array
    {
        $trip = $booking->schedule?->trip;
        $requirements = TripDocumentRequirements::forCustomers($trip?->document_requirements);

        $booking->loadMissing('passengers');
        if ($booking->passengers->contains(fn (BookingPassenger $passenger) => $this->isBorderPass($booking, $passenger))) {
            $requirements = [...$requirements, ...TripDocumentRequirements::borderPass()];
        }

        return $requirements;
    }

    /** เอกสารรายการนี้ต้องขอจากผู้เดินทางคนนี้ไหม */
    public function appliesTo(array $requirement, Booking $booking, BookingPassenger $passenger): bool
    {
        return ($requirement['for'] ?? TripDocumentRequirements::FOR_ALL) !== TripDocumentRequirements::FOR_BORDER_PASS
            || $this->isBorderPass($booking, $passenger);
    }

    private function isBorderPass(Booking $booking, BookingPassenger $passenger): bool
    {
        return $booking->schedule?->trip?->resolveTravelDocument($passenger->travel_document, $passenger->nationality)
            === Trip::DOC_BORDER_PASS;
    }

    public function isRequired(Booking $booking): bool
    {
        return $this->requirementsFor($booking) !== [];
    }

    /**
     * เอกสารบังคับที่ยังไม่มีใครส่ง รวมทั้งใบจอง — พร้อมชื่อคนที่ยังขาด
     *
     * ใบเดินทางเอาไปขึ้นเป็นรายการ "ยังขาดอะไรอยู่" ให้ลูกค้าที่ไม่ได้โหลดแอปเห็น
     * กติกาว่าอะไรนับว่าขาด (บังคับ + ยังไม่มีไฟล์ของคนนั้น) ต้องเป็นกติกาเดียว
     * กับ [payload] ที่หน้าจอในแอปใช้ จึงอยู่ในคลาสนี้ ไม่ใช่ไปเขียนซ้ำที่ปลายทาง
     *
     * @return array<int, array{key: string, label: string, note: string, passengers: array<int, string>}>
     */
    public function missingRequirements(Booking $booking): array
    {
        $requirements = array_values(array_filter(
            $this->requirementsFor($booking),
            fn (array $requirement) => $requirement['required'],
        ));

        if ($requirements === []) {
            return [];
        }

        $booking->loadMissing(['passengers', 'documents']);
        $byPassenger = $booking->documents->groupBy('booking_passenger_id');

        $missing = [];

        foreach ($requirements as $requirement) {
            $names = $booking->passengers
                ->filter(fn (BookingPassenger $passenger) => $this->appliesTo($requirement, $booking, $passenger))
                ->filter(fn (BookingPassenger $passenger) => ($byPassenger->get($passenger->id) ?? collect())
                    ->where('requirement_key', $requirement['key'])
                    ->isEmpty())
                ->pluck('name')
                ->filter()
                ->values()
                ->all();

            if ($names === []) {
                continue;
            }

            $missing[] = [
                'key' => $requirement['key'],
                'label' => $requirement['label'],
                'note' => $requirement['note'],
                'passengers' => $names,
            ];
        }

        return $missing;
    }

    /**
     * เอกสารบังคับที่แต่ละคนยังขาด — สำหรับแจ้งเตือนรายคน
     *
     * ต่างจาก [missingRequirements] ตรงที่ตัดคนที่แจ้งว่า "ไปไม่ได้" ออก (ที่นั่ง
     * ยังอยู่ แต่ไม่มีเหตุให้ทวงเอกสารของคนที่ไม่ไป)
     *
     * @return array<int, array<int, string>> passenger_id → ชื่อเอกสารที่ยังขาด
     */
    public function missingByPassenger(Booking $booking): array
    {
        $requirements = array_values(array_filter(
            $this->requirementsFor($booking),
            fn (array $requirement) => $requirement['required'],
        ));

        if ($requirements === []) {
            return [];
        }

        $booking->loadMissing(['passengers', 'documents']);
        $keysByPassenger = $booking->documents->groupBy('booking_passenger_id')
            ->map(fn ($documents) => $documents->pluck('requirement_key')->all());

        $missing = [];

        foreach ($booking->passengers->whereNull('not_going_at') as $passenger) {
            $have = $keysByPassenger->get($passenger->id, []);
            $labels = collect($requirements)
                ->filter(fn (array $requirement) => $this->appliesTo($requirement, $booking, $passenger))
                ->reject(fn (array $requirement) => in_array($requirement['key'], $have, true))
                ->pluck('label')
                ->values()
                ->all();

            if ($labels !== []) {
                $missing[$passenger->id] = $labels;
            }
        }

        return $missing;
    }

    /**
     * เก็บไฟล์หนึ่งใบ
     *
     * @throws \Exception เมื่อทริปไม่ได้ขอเอกสารชิ้นนี้ หรือแนบเกินโควตา
     */
    public function store(
        Booking $booking,
        BookingPassenger $passenger,
        string $requirementKey,
        UploadedFile $file,
        ?int $uploadedById,
    ): BookingDocument {
        $requirement = collect($this->requirementsFor($booking))->firstWhere('key', $requirementKey);

        if (! $requirement) {
            throw new \Exception(TripDocumentRequirements::isBorderPassKey($requirementKey)
                ? 'บัตรประชาชนใช้สำหรับคนที่เลือกทำ Border Pass เท่านั้น'
                : 'ทริปนี้ไม่ได้ขอเอกสารรายการนี้');
        }

        if (! $this->appliesTo($requirement, $booking, $passenger)) {
            throw new \Exception('บัตรประชาชนใช้สำหรับคนที่เลือกทำ Border Pass เท่านั้น');
        }

        // บัตรหน้า/หลังมีได้ใบเดียวต่อด้าน — แนบใหม่ = แทนที่ ไม่เก็บสำเนาบัตร
        // ประชาชนค้างไว้หลายใบโดยไม่จำเป็น (ลบของเดิมหลังเก็บใบใหม่สำเร็จแล้ว)
        $replacing = TripDocumentRequirements::isBorderPassKey($requirementKey)
            ? BookingDocument::where('booking_passenger_id', $passenger->id)
                ->where('requirement_key', $requirementKey)
                ->get()
            : collect();

        $existing = $replacing->isNotEmpty() ? 0 : BookingDocument::where('booking_passenger_id', $passenger->id)
            ->where('requirement_key', $requirementKey)
            ->count();

        if ($existing >= self::MAX_FILES_PER_SLOT) {
            throw new \Exception('แนบไฟล์ได้สูงสุด '.self::MAX_FILES_PER_SLOT.' ไฟล์ต่อเอกสารหนึ่งรายการ');
        }

        $path = $file->store('booking-documents/'.date('Y/m'), MediaDisk::slipDisk());

        if (! $path) {
            throw new \Exception('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }

        $document = BookingDocument::create([
            'booking_id' => $booking->id,
            'booking_passenger_id' => $passenger->id,
            'requirement_key' => $requirement['key'],
            // snapshot ข้อความตอนที่ถูกขอ — แอดมินแก้ทริปทีหลังได้โดยไฟล์เก่าไม่เพี้ยน
            'label' => $requirement['label'],
            'note' => $requirement['note'] ?: null,
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName() ?: 'document', 0, 255),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by_id' => $uploadedById,
        ]);

        $replacing->each(fn (BookingDocument $old) => $this->delete($old));

        return $document;
    }

    private function isHeldByIntake(string $path): bool
    {
        return CustomerIntakePerson::where('border_pass_front_path', $path)
            ->orWhere('border_pass_back_path', $path)
            ->exists();
    }

    /**
     * ผูกรูปบัตรประชาชนที่ลูกค้าแนบมากับลิงก์รับข้อมูลเข้ากับผู้เดินทางในใบจอง
     *
     * จับคู่ด้วยเลขบัตรประชาชน ไม่ใช่ลำดับ — หน้าจองแทนลูกค้ารวมหลายกลุ่ม ตัดคน
     * ซ้ำ และให้แอดมินสลับลำดับได้ เลขบัตรคือสิ่งเดียวที่ชี้ตัวคนได้แน่นอน (และ
     * คือเลขที่ด่านจะเทียบกับรูปอยู่แล้ว) ไฟล์ใช้ร่วมกัน ไม่คัดลอก
     *
     * @param  iterable<CustomerIntakePerson>  $people
     */
    public function adoptIntakeIdFiles(Booking $booking, iterable $people): int
    {
        $booking->load(['passengers', 'documents', 'schedule.trip']);
        $digits = fn ($value) => preg_replace('/\D/', '', (string) $value);
        $labels = collect(TripDocumentRequirements::borderPass())->keyBy('key');
        $adopted = 0;

        foreach ($people as $person) {
            $idCard = $digits($person->id_card);
            if ($idCard === '') {
                continue;
            }

            $passenger = $booking->passengers->first(
                fn (BookingPassenger $passenger) => $digits($passenger->id_card) === $idCard
                    && $this->isBorderPass($booking, $passenger)
            );
            if (! $passenger) {
                continue;
            }

            foreach (CustomerIntakePerson::ID_FILE_COLUMNS as $key => $column) {
                $path = $person->{$column};
                $already = $booking->documents
                    ->where('booking_passenger_id', $passenger->id)
                    ->where('requirement_key', $key)
                    ->isNotEmpty();

                if (blank($path) || $already) {
                    continue;
                }

                BookingDocument::create([
                    'booking_id' => $booking->id,
                    'booking_passenger_id' => $passenger->id,
                    'requirement_key' => $key,
                    'label' => $labels[$key]['label'],
                    'note' => $labels[$key]['note'],
                    'file_path' => $path,
                    'original_name' => $labels[$key]['label'],
                    'mime_type' => $this->mimeOf($path),
                    'size' => 0,
                    'uploaded_by_id' => null,
                ]);
                $adopted++;
            }
        }

        return $adopted;
    }

    private function mimeOf(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic', 'heif' => 'image/heic',
            default => 'image/jpeg',
        };
    }

    /**
     * ลบบัตรประชาชนของ Border Pass ของคนนี้ทั้งหมด — เปลี่ยนไปใช้พาสปอร์ต
     * ยกเลิกการจอง หรือจบทริปแล้ว ไม่มีเหตุให้ถือสำเนาบัตรต่อ
     */
    public function purgeBorderPass(BookingPassenger $passenger): int
    {
        $documents = BookingDocument::where('booking_passenger_id', $passenger->id)
            ->whereIn('requirement_key', TripDocumentRequirements::BORDER_PASS_KEYS)
            ->get();

        $documents->each(fn (BookingDocument $document) => $this->delete($document));

        return $documents->count();
    }

    /** ลบทั้งแถวและไฟล์จริง — ไฟล์ที่ไม่มีใครอ้างถึงแล้วไม่ควรค้างบนดิสก์ */
    public function delete(BookingDocument $document): void
    {
        $path = $document->file_path;
        $document->delete();

        // รูปบัตรที่มาจากลิงก์รับข้อมูลยังถูกกลุ่มลูกค้าถืออยู่ (ใบจองที่จ่ายไม่ทันแล้ว
        // กลุ่มกลับมาจองใหม่ต้องได้ไฟล์เดิม) — กลุ่มถูกลบเมื่อไหร่ไฟล์ค่อยหายตาม
        if ($this->isHeldByIntake($path) || BookingDocument::where('file_path', $path)->exists()) {
            return;
        }

        try {
            Storage::disk(MediaDisk::slipDisk())->delete($path);
        } catch (\Throwable $e) {
            // แถวหายแล้วคือลูกค้าเห็นว่าลบสำเร็จ ไฟล์ค้างบนดิสก์ไม่ควรทำให้ error
        }
    }

    /**
     * ผังเอกสารทั้งการจอง: ข้อกำหนด × ผู้เดินทาง พร้อมไฟล์ที่แนบแล้ว
     *
     * รูปแบบเดียวกันทั้งหน้าเว็บ แอป และหน้าแอดมิน จะได้ไม่ต้องประกอบเองสามที่
     */
    public function payload(Booking $booking): array
    {
        $requirements = $this->requirementsFor($booking);

        $booking->loadMissing(['passengers', 'documents']);
        $byPassenger = $booking->documents->groupBy('booking_passenger_id');

        $passengers = $booking->passengers->map(function (BookingPassenger $passenger) use ($booking, $requirements, $byPassenger) {
            $documents = $byPassenger->get($passenger->id) ?? collect();

            return [
                'passenger_id' => $passenger->id,
                'name' => $passenger->name,
                // เฉพาะรายการที่ขอจากคนนี้จริง — บัตรประชาชนของ Border Pass ขึ้นแค่
                // กับคนที่เลือก Border Pass
                'requirements' => collect($requirements)
                    ->filter(fn (array $requirement) => $this->appliesTo($requirement, $booking, $passenger))
                    ->map(function (array $requirement) use ($documents) {
                        $files = $documents
                            ->where('requirement_key', $requirement['key'])
                            ->map(fn (BookingDocument $doc) => $this->filePayload($doc))
                            ->values();

                        return [
                            ...$requirement,
                            'files' => $files,
                            'is_missing' => $requirement['required'] && $files->isEmpty(),
                        ];
                    })->values(),
            ];
        })->values();

        return [
            'booking_ref' => $booking->booking_ref,
            'requirements' => $requirements,
            'max_files_per_slot' => self::MAX_FILES_PER_SLOT,
            'max_size_mb' => (int) round(self::MAX_SIZE_KB / 1024),
            'accepted_types' => self::ALLOWED_MIMES,
            'passengers' => $passengers,
            // ยังมีใครขาดเอกสารที่บังคับอยู่ไหม — หน้าจอเอาไปขึ้นป้ายเตือนได้เลย
            'has_missing' => $passengers->contains(
                fn (array $row) => collect($row['requirements'])->contains('is_missing', true)
            ),
            // แนบอะไรไว้บ้างหรือยัง — แยกจาก has_missing เพราะทริปที่มีแต่เอกสาร
            // ไม่บังคับ has_missing เป็น false ตั้งแต่ยังไม่แนบสักไฟล์ หน้าจอที่อ่านแค่
            // has_missing จะขึ้น "เอกสารครบแล้ว" ทั้งที่ว่างเปล่า
            'has_any_file' => $booking->documents->isNotEmpty(),
        ];
    }

    /** หนึ่งไฟล์ในรูปแบบที่หน้าจอใช้ได้ — ลิงก์เป็น signed URL อายุสั้น */
    public function filePayload(BookingDocument $document): array
    {
        return [
            'id' => $document->id,
            'requirement_key' => $document->requirement_key,
            'label' => $document->label,
            'note' => $document->note,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'is_image' => $document->isImage(),
            'size' => $document->size,
            'url' => $document->url(),
            'uploaded_at' => $document->created_at?->toISOString(),
        ];
    }
}
