<?php

namespace App\Observers;

use App\Jobs\AnnounceItineraryChangeJob;
use App\Models\ScheduleItineraryItem;

/**
 * แก้กำหนดการเมื่อไหร่ ห้องแชทของรอบนั้นต้องรู้ (AnnounceItineraryChangeJob
 * เป็นคนตัดสินว่าควรโพสต์จริงไหม — ที่นี่แค่ส่งสัญญาณ)
 */
class ScheduleItineraryItemObserver
{
    /**
     * ฟิลด์ที่ "ลูกค้าอ่านแล้วต่างออกไป" เท่านั้นที่นับว่ากำหนดการเปลี่ยน
     *
     * ไม่รวม reached_at/reached_by ซึ่งเป็นการเช็คอินของสตาฟระหว่างทริป
     * (กดทีก็เด้งข้อความทีคงไม่ไหว) และไม่รวม sort_order ที่เป็นการจัดหน้า
     * ของแอดมินตอนเขียน ไม่ใช่การเปลี่ยนแผน
     */
    private const CONTENT_FIELDS = ['item_date', 'time', 'title', 'detail', 'link'];

    public function created(ScheduleItineraryItem $item): void
    {
        $this->announce($item);
    }

    public function updated(ScheduleItineraryItem $item): void
    {
        if ($item->wasChanged(self::CONTENT_FIELDS)) {
            $this->announce($item);
        }
    }

    public function deleted(ScheduleItineraryItem $item): void
    {
        $this->announce($item);
    }

    private function announce(ScheduleItineraryItem $item): void
    {
        AnnounceItineraryChangeJob::dispatch($item->schedule_id)
            ->delay(now()->addMinutes(AnnounceItineraryChangeJob::DEBOUNCE_MINUTES));
    }
}
