<?php

namespace App\Jobs;

use App\Models\CustomerIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ลบข้อมูลลูกค้าที่กรอกผ่านลิงก์แล้วไม่ได้ถูกใช้ต่อ
 *
 * ตารางนี้เก็บเลขบัตรประชาชนและข้อมูลสุขภาพของคนที่ยังไม่ได้เป็นลูกค้าเราด้วยซ้ำ
 * เก็บไว้นานกว่าที่จำเป็นคือความเสี่ยงล้วน ๆ ไม่ใช่ทรัพย์สิน
 *
 *  - ยังไม่ถูกดึงไปจอง: ลบเมื่อเงียบเกิน {@see CustomerIntake::RETENTION_DAYS} วัน
 *    นับจากความเคลื่อนไหวครั้งล่าสุด ไม่ใช่วันที่สร้าง — กลุ่มที่เพื่อนยังทยอย
 *    เข้ามากรอกอยู่จึงไม่ถูกลบทิ้งกลางคัน
 *  - ถูกดึงไปจอง/เก็บเข้ากรุแล้ว: ข้อมูลไปอยู่บนการจองจริงแล้ว เหลือไว้กันเหนียว
 *    {@see CustomerIntake::CONVERTED_RETENTION_DAYS} วันเผื่อการจองถูกยกเลิก
 *
 * ผู้เดินทางในกลุ่มถูกลบตามด้วย cascade ที่ระดับ foreign key
 */
class PurgeStaleCustomerIntakesJob implements ShouldQueue
{
    use Queueable;

    private const CHUNK = 200;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $deleted = 0;

        CustomerIntake::dueForPurge()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($intakes) use (&$deleted) {
                $ids = $intakes->pluck('id')->all();
                // ลบผ่าน query builder ตัวเดียว — cascade ที่ customer_intake_people
                // เก็บลูกให้เอง ไม่ต้องวนลบทีละแถว
                CustomerIntake::whereIn('id', $ids)->delete();
                $deleted += count($ids);
            });

        if ($deleted > 0) {
            Log::info('PurgeStaleCustomerIntakesJob completed', [
                'open_retention_days' => CustomerIntake::RETENTION_DAYS,
                'converted_retention_days' => CustomerIntake::CONVERTED_RETENTION_DAYS,
                'intakes_deleted' => $deleted,
            ]);
        }
    }
}
