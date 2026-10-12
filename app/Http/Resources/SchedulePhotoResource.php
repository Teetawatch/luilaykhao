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
            // ใครอัป — ส่งเฉพาะฝั่งแอดมิน/สตาฟที่โหลด uploader มา (สตาฟลบได้เฉพาะรูป
            // ของตัวเอง) อัลบั้มสาธารณะกับหน้ารูปของลูกค้าไม่ต้องรู้ว่าใครเป็นคนอัป
            'uploaded_by' => $this->when($this->relationLoaded('uploader'), $this->uploaded_by),
            'uploader_name' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            // รูปถูกลบอัตโนมัติหลังอัปโหลดครบกำหนด — ส่งเวลาหมดอายุไปด้วยเพื่อให้
            // แอป/หน้าอัลบั้มเตือนลูกค้าให้ดาวน์โหลดทัน
            'uploaded_at' => $this->created_at?->toISOString(),
            'expires_at' => $this->expiresAt()?->toISOString(),
        ];
    }
}
