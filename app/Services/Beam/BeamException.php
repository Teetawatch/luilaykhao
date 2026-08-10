<?php

namespace App\Services\Beam;

/**
 * Beam ตอบไม่สำเร็จ หรือติดต่อไม่ได้
 *
 * แยกจาก \Exception ทั่วไปเพราะฝั่งที่เรียกต้องตัดสินใจต่างกัน: ถ้าเป็นตัวนี้แปลว่า
 * "เกตเวย์มีปัญหา" ไม่ใช่ "ลูกค้าทำอะไรผิด" — จึงตกกลับไปหน้าโอนเอง + สลิปได้
 * โดยไม่ต้องโทษลูกค้า
 */
class BeamException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message);
    }
}
