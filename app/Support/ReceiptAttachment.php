<?php

namespace App\Support;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Mail\Mailables\Attachment;

class ReceiptAttachment
{
    /**
     * แนบไฟล์ PDF ใบเสร็จเข้ากับ Mailable — สร้าง PDF ตอนส่ง (บนคิว)
     * ถ้าไม่มีใบเสร็จ (ออกไม่สำเร็จ) ก็ส่งอีเมลได้ตามปกติโดยไม่มีไฟล์แนบ
     *
     * @return array<int, Attachment>
     */
    public static function for(?Receipt $receipt): array
    {
        if ($receipt === null) {
            return [];
        }

        $service = app(ReceiptService::class);

        return [
            Attachment::fromData(
                fn () => $service->pdf($receipt)->output(),
                $service->pdfFilename($receipt),
            )->withMime('application/pdf'),
        ];
    }
}
