<?php

namespace App\Http\Resources;

use App\Models\PickupVehicleClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PickupVehicleClass
 */
class PickupVehicleClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'min_pax' => $this->min_pax,
            'max_pax' => $this->max_pax,
            // ให้ฝั่งแอป/เว็บวาดได้เลยโดยไม่ต้องประกอบข้อความช่วงคนเอง
            'pax_label' => $this->paxLabel(),
            'image_url' => $this->image_url,
            'note' => $this->note,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}
