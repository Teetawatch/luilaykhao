<?php

use App\Services\PermitSignatureService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ลายเซ็นแบบฟอร์มอุทยานเปลี่ยนเป็นสีน้ำเงิน (ปากกา) — ย้อมไฟล์ที่เซ็นไว้ก่อนหน้าให้ตรงกัน
 * ไม่มีทางย้อนกลับ (ไม่จำเป็น) ไฟล์พวกนี้ถูกลบเองหลังจบทริปอยู่แล้ว
 */
return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk(PermitSignatureService::DISK);

        DB::table('booking_passengers')
            ->whereNotNull('permit_signature_path')
            ->orderBy('id')
            ->select(['id', 'permit_signature_path'])
            ->chunk(200, function ($rows) use ($disk) {
                foreach ($rows as $row) {
                    try {
                        if ($disk->exists($row->permit_signature_path)) {
                            $disk->put($row->permit_signature_path, PermitSignatureService::ink($disk->get($row->permit_signature_path)));
                        }
                    } catch (Throwable) {
                        // ไฟล์เสีย/อ่านไม่ได้ — ปล่อยไว้ เดี๋ยวก็ถูกล้างตามรอบ
                    }
                }
            });
    }

    public function down(): void
    {
        //
    }
};
