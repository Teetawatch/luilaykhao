<?php

namespace App\Services;

use App\Models\SaleCampaign;

/**
 * ตัวกลางที่บอกว่า "ตอนนี้มีแคมเปญวันพิเศษอยู่ไหม" ให้ทุกจุดที่คิดราคา
 *
 * ถูกเรียกซ้ำมากใน request เดียว (ราคารอบ / ราคาจุดขึ้นรถ / ราคาจอย ของทุก
 * ทริปในหน้ารายการ) จึงจำคำตอบไว้ในหน่วยความจำต่อหนึ่ง request — ลงทะเบียน
 * เป็น singleton ใน AppServiceProvider ทำให้เทสต์แต่ละเคสเริ่มใหม่เองตาม
 * container ไม่ต้องมานั่งล้าง static ทิ้ง
 */
class SaleCampaignService
{
    private bool $resolved = false;

    private ?SaleCampaign $campaign = null;

    /** แคมเปญที่กำลังลดราคาอยู่ตอนนี้ (ถ้ามี) */
    public function active(): ?SaleCampaign
    {
        if (! $this->resolved) {
            $this->campaign = SaleCampaign::live()->orderByDesc('starts_at')->first();
            $this->resolved = true;
        }

        return $this->campaign;
    }

    /** แคมเปญที่มีผลกับทริปนี้ — null ถ้าไม่มีแคมเปญ หรือทริปนี้ถูกยกเว้น */
    public function forTrip(?int $tripId): ?SaleCampaign
    {
        $campaign = $this->active();

        return $campaign && $campaign->appliesToTrip($tripId) ? $campaign : null;
    }

    /** ลืมคำตอบที่จำไว้ — ใช้หลังแอดมินแก้แคมเปญกลาง request เดียวกัน */
    public function flush(): void
    {
        $this->resolved = false;
        $this->campaign = null;
    }
}
