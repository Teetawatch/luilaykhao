<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SchedulePhotoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // schedule_id / sort_order now live on the schedule_photo pivot. They are
        // present whenever the photo is loaded through a schedule relation.
        return [
            'id' => $this->id,
            'schedule_id' => $this->pivot?->schedule_id,
            'url' => $this->public_url,
            'thumb_url' => $this->thumb_public_url,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'sort_order' => $this->pivot?->sort_order ?? 0,
            // รูปถูกลบอัตโนมัติหลังอัปโหลดครบกำหนด — ส่งเวลาหมดอายุไปด้วยเพื่อให้
            // แอป/หน้าอัลบั้มเตือนลูกค้าให้ดาวน์โหลดทัน
            'uploaded_at' => $this->created_at?->toISOString(),
            'expires_at' => $this->expiresAt()?->toISOString(),
        ];
    }
}
