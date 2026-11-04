<?php

namespace App\Models;

use App\Support\PermitFormFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * แบบฟอร์มขออนุญาตเข้าพื้นที่ (อุทยาน/หน่วยงาน) ที่ต้องกรอกต่อผู้เดินทางหนึ่งคน
 *
 * ทริปหลายทริปใช้แบบฟอร์มเดียวกันได้ (`trips.permit_form_id`) ดู PermitFormService
 *
 * @property array $pages [{path, width_mm, height_mm}]
 * @property array|null $fields [{id, page, source, x, y, w, h, size, align, text?}]
 */
class PermitForm extends Model
{
    protected $fillable = ['name', 'note', 'source_path', 'source_name', 'pages', 'fields', 'sign_due_days'];

    protected function casts(): array
    {
        return [
            'pages' => 'array',
            'fields' => 'array',
            'sign_due_days' => 'integer',
        ];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /** มีช่องลายเซ็นวางไว้ = ผู้เดินทางต้องเซ็นก่อนลงทะเบียนกับอุทยาน */
    public function requiresSignature(): bool
    {
        return collect($this->fields ?? [])->contains('source', PermitFormFields::SIGNATURE);
    }

    /**
     * ข้อความแจ้งในหน้าจอง (เว็บ/แอป/LIFF วาดตามนี้) — บอกล่วงหน้าว่าหลังจองต้องเซ็น
     * แบบฟอร์ม และอุทยานขอที่อยู่หรือข้อมูลอื่นอะไรบ้าง ไม่ได้ถามตอนจอง (ดู
     * PermitFormFields::askSpec) แค่ให้รู้ตัวและเตรียมไว้
     *
     * null = ไม่มีอะไรให้ลูกค้าทำ (ฟอร์มไม่มีช่องลายเซ็น ทีมงานกรอกยื่นให้เอง)
     *
     * @return array{title: string, body: string}|null
     */
    public function customerNotice(): ?array
    {
        if (! $this->requiresSignature()) {
            return null;
        }

        $address = [];
        $questions = [];
        foreach (PermitFormFields::askSpec($this->fields ?? []) as $item) {
            if ($item['custom']) {
                $questions[] = $item['label'];

                continue;
            }
            $address[] = $item['label'];
            // รหัสไปรษณีย์ระบบหาให้จากตำบล — แต่อุทยานขอ ลูกค้าควรรู้ว่าอยู่ในชุดนี้
            if ($item['key'] === 'province') {
                $address[] = 'รหัสไปรษณีย์';
            }
        }

        $needs = array_filter([
            $address === [] ? null : 'ที่อยู่ตามทะเบียนบ้าน ('.implode(' ', $address).')',
            $questions === [] ? null : 'ข้อมูลเพิ่มเติม ('.implode(', ', $questions).')',
        ]);

        $body = $needs === []
            ? 'อุทยานกำหนดให้ผู้เดินทางทุกคนเซ็นแบบฟอร์มของอุทยาน ข้อมูลที่กรอกตอนจองจะถูกเติมลงแบบฟอร์มให้ '
                .'หลังจองแล้วแต่ละคนจะได้ลิงก์ไปตรวจและเซ็นบนมือถือก่อนเดินทาง'
            : 'อุทยานกำหนดให้ผู้เดินทางทุกคนเซ็นแบบฟอร์มของอุทยาน และต้องใช้'.implode(' และ', $needs)
                .' ไม่ต้องกรอกตอนจอง หลังจองแล้วแต่ละคนจะได้ลิงก์ไปกรอก'.($questions === [] ? 'ที่อยู่' : 'ข้อมูล')
                .'และเซ็นบนมือถือก่อนเดินทาง';

        return ['title' => 'ทริปนี้มีแบบฟอร์มอุทยาน', 'body' => $body];
    }
}
