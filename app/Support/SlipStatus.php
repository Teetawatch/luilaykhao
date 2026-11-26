<?php

namespace App\Support;

/**
 * สถานะการตรวจสลิปโอนเงิน (คอลัมน์ *_slip_ocr_status — ชื่อคอลัมน์เป็นของเดิม)
 *
 * ระบบไม่ได้อ่านสลิปด้วย OCR แล้ว (เลิกใช้ 2026-11-26 — ลูกค้าจ่ายผ่าน Beam) ทุก
 * สลิปที่ยังอัปโหลดเข้ามาเป็น PENDING = รอทีมงานตรวจยอดเอง แล้วทีมงานกด
 * APPROVED / REJECTED จากหน้าแอดมิน VERIFIED กับ FAILED เป็นผลของ OCR เดิม
 * ยังมีอยู่ในแถวเก่า ห้ามลบค่าเหล่านี้ออกจากหน้าจอที่ยังแสดงประวัติ
 */
final class SlipStatus
{
    /** แนบสลิปแล้ว รอทีมงานตรวจยอด */
    public const PENDING = 'pending';

    /** (เลิกใช้) OCR เดิมอ่านแล้วยอดตรง — มีเฉพาะแถวเก่า */
    public const VERIFIED = 'verified';

    /** (เลิกใช้) OCR เดิมอ่านแล้วไม่ผ่าน — มีเฉพาะแถวเก่า */
    public const FAILED = 'failed';

    /** ทีมงานตรวจแล้วว่าเงินเข้า */
    public const APPROVED = 'manually_approved';

    /** ทีมงานตรวจแล้วว่าไม่ถูกต้อง */
    public const REJECTED = 'rejected';
}
