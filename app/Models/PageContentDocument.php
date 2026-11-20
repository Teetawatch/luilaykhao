<?php

namespace App\Models;

use App\Jobs\TranslateContentJob;
use App\Models\Concerns\HasContentTranslations;
use App\Support\PageContent;
use Illuminate\Database\Eloquent\Model;

/**
 * หน้าเนื้อหาที่แอดมินแก้ได้ (FAQ วิธีการจอง เช็คลิสต์ ระดับความยาก ปัญหาที่เราแก้)
 * ในรูปที่ระบบแปลเนื้อหาเข้าใจ — ไม่มีตารางของตัวเอง เนื้อหาจริงอยู่ใน settings
 * (App\Support\PageContent) ตัวนี้แค่ให้หน้าเหล่านั้นมีเลขประจำตัวสำหรับเก็บคำแปล
 *
 * เลขในแต่ละคีย์ห้ามเปลี่ยน — content_translations ผูกคำแปลด้วยเลขนี้
 */
class PageContentDocument extends Model
{
    use HasContentTranslations;

    public const IDS = [
        'checklist' => 1,
        'difficulty' => 2,
        'faq' => 3,
        'booking_guide' => 4,
        'problem' => 5,
    ];

    protected array $translatableContent = ['content'];

    /** ไม่มีแถวให้ saved() — PageContent::put สั่งแปลเอง */
    protected bool $translateOnSave = false;

    protected $guarded = [];

    public $timestamps = false;

    public static function for(string $key): ?self
    {
        $id = self::IDS[$key] ?? null;
        if ($id === null || ! PageContent::has($key)) {
            return null;
        }

        $document = new self;
        $document->setAttribute('id', $id);
        $document->setAttribute('key', $key);
        $document->exists = true;

        return $document;
    }

    public static function findForTranslation(int $id): ?self
    {
        $key = array_search($id, self::IDS, true);

        return $key === false ? null : self::for($key);
    }

    /** @return list<self> */
    public static function allForTranslation(): array
    {
        return array_values(array_filter(array_map(self::for(...), array_keys(self::IDS))));
    }

    /** แอดมินบันทึกหน้าเนื้อหา — แปลใหม่เฉพาะช่องที่ข้อความไทยเปลี่ยน */
    public static function queueTranslation(string $key): void
    {
        if (! config('services.anthropic.content_translate_on_save', true)) {
            return;
        }

        if ($document = self::for($key)) {
            TranslateContentJob::dispatchFor($document);
        }
    }

    public function translationSource(string $field): mixed
    {
        return $field === 'content' ? PageContent::get((string) $this->getAttribute('key')) : null;
    }
}
