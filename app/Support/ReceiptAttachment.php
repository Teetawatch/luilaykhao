<?php

namespace App\Support;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Log;

class ReceiptAttachment
{
    /**
     * แนบไฟล์ PDF ใบเสร็จเข้ากับ Mailable — เรนเดอร์ PDF ตอนส่ง (บนคิว)
     * ถ้าไม่มีใบเสร็จ หรือเรนเดอร์ PDF ไม่สำเร็จ ก็ยังส่งอีเมลยืนยันได้ตามปกติ
     * โดยไม่มีไฟล์แนบ (กันไม่ให้ทั้งอีเมลล้มเพราะ dompdf พัง) และ log สาเหตุไว้
     *
     * @return array<int, Attachment>
     */
    public static function for(?Receipt $receipt): array
    {
        if ($receipt === null) {
            return [];
        }

        try {
            $service = app(ReceiptService::class);
            $bytes = $service->pdf($receipt)->output();
            $filename = $service->pdfFilename($receipt);
        } catch (\Throwable $e) {
            Log::error('ReceiptAttachment: failed to render receipt PDF — sending email without attachment', [
                'receipt_no' => $receipt->receipt_no,
                'booking_id' => $receipt->booking_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return [
            Attachment::fromData(fn () => $bytes, $filename)->withMime('application/pdf'),
        ];
    }
}
