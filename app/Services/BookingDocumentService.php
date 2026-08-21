<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
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
     * ทริปของการจองนี้ขออะไรบ้าง (จัดระเบียบแล้ว)
     *
     * @return array<int, array{key: string, label: string, note: string, required: bool}>
     */
    public function requirementsFor(Booking $booking): array
    {
        return TripDocumentRequirements::normalize(
            $booking->schedule?->trip?->document_requirements
        );
    }

    public function isRequired(Booking $booking): bool
    {
        return $this->requirementsFor($booking) !== [];
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
        $requirement = TripDocumentRequirements::find(
            $booking->schedule?->trip?->document_requirements,
            $requirementKey,
        );

        if (! $requirement) {
            throw new \Exception('ทริปนี้ไม่ได้ขอเอกสารรายการนี้');
        }

        $existing = BookingDocument::where('booking_passenger_id', $passenger->id)
            ->where('requirement_key', $requirementKey)
            ->count();

        if ($existing >= self::MAX_FILES_PER_SLOT) {
            throw new \Exception('แนบไฟล์ได้สูงสุด '.self::MAX_FILES_PER_SLOT.' ไฟล์ต่อเอกสารหนึ่งรายการ');
        }

        $path = $file->store('booking-documents/'.date('Y/m'), MediaDisk::slipDisk());

        if (! $path) {
            throw new \Exception('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
        }

        return BookingDocument::create([
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
    }

    /** ลบทั้งแถวและไฟล์จริง — ไฟล์ที่ไม่มีใครอ้างถึงแล้วไม่ควรค้างบนดิสก์ */
    public function delete(BookingDocument $document): void
    {
        $path = $document->file_path;
        $document->delete();

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

        $passengers = $booking->passengers->map(function (BookingPassenger $passenger) use ($requirements, $byPassenger) {
            $documents = $byPassenger->get($passenger->id) ?? collect();

            return [
                'passenger_id' => $passenger->id,
                'name' => $passenger->name,
                'requirements' => collect($requirements)->map(function (array $requirement) use ($documents) {
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
