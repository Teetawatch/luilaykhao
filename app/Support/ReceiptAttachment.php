<?php

namespace App\Support;

use App\Models\Receipt;
use App\Services\ReceiptService;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Log;

class ReceiptAttachment
{
    /**
     * ไฟล์แนบของอีเมลยืนยันการชำระ ตามว่าผู้รับเป็นใคร
     *
     * ผู้จองได้ใบรวมเป็นไฟล์ ส่วนใบแยกของเพื่อนอยู่ในอีเมลเป็นลิงก์ — แนบครบ
     * ทุกคนอีเมลจะบวมตามขนาดคณะ เพื่อนได้ใบของตัวเองครบทุกใบ เพราะอีเมล
     * เดียวกันอาจกรอกไว้ให้หลายคน (พ่อแม่กรอกให้ลูก)
     *
     * @param  iterable<int, Receipt>|null  $personal
     * @return array<int, Attachment>
     */
    public static function forEmail(?Receipt $receipt, ?iterable $personal = null): array
    {
        if ($receipt === null || ! $receipt->isPersonal()) {
            return self::for($receipt);
        }

        return collect($personal ?? [$receipt])
            ->flatMap(fn (Receipt $r) => self::for($r))
            ->values()
            ->all();
    }

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
