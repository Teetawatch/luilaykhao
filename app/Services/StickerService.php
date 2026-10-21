<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\Sticker;
use App\Models\StickerPack;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * สติกเกอร์ของแบรนด์ — อัปโหลดจากหลังบ้าน แสดงในแผงสติกเกอร์ของแอป
 *
 * ไฟล์ที่อัปขึ้นมามักเป็น PNG ขนาดสำหรับพิมพ์ (1000px+) แต่ในห้องแชทแสดงแค่ราว
 * 130 จุด จึงย่อภาพนิ่งเหลือด้านยาว MAX_EDGE โดยคงพื้นใสไว้ ส่วนภาพเคลื่อนไหว
 * (GIF / WebP หลายเฟรม) เก็บตามต้นฉบับ เพราะ GD อ่านได้แค่เฟรมแรก ย่อแล้วจะนิ่ง
 */
class StickerService
{
    /** ด้านยาวสุดของภาพนิ่งที่เก็บ */
    public const MAX_EDGE = 512;

    /** ขนาดไฟล์สูงสุดที่รับ (KB) — ภาพเคลื่อนไหวไม่ถูกย่อ จึงต้องมีเพดาน */
    public const MAX_KB = 2048;

    public const EXTENSIONS = ['png', 'webp', 'gif', 'jpg', 'jpeg'];

    /** อัปโหลดทีละกี่ไฟล์ได้ */
    public const MAX_BATCH = 40;

    /**
     * ชุดสติกเกอร์ที่ลูกค้าใช้ได้ (ชุดเปิด + สติกเกอร์เปิด) — ชุดว่างไม่ส่ง
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return StickerPack::query()
            ->where('is_active', true)
            ->with(['stickers' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (StickerPack $pack) => $pack->stickers->isNotEmpty())
            ->map(fn (StickerPack $pack) => [
                'id' => $pack->id,
                'name' => $pack->name,
                'stickers' => $pack->stickers->map(fn (Sticker $s) => $this->present($s))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Sticker $sticker): array
    {
        return [
            'id' => $sticker->id,
            'label' => $sticker->label,
            'keywords' => $sticker->keywordList(),
            'url' => $sticker->url,
            'width' => $sticker->width,
            'height' => $sticker->height,
            'is_animated' => $sticker->is_animated,
        ];
    }

    /**
     * สติกเกอร์ของข้อความ (ถ้ามีและยังอยู่) — ข้อความที่ถูกลบไม่ส่ง
     *
     * @return array<string, mixed>|null
     */
    public function forMessage(ChatMessage $message): ?array
    {
        if ($message->is_deleted || $message->sticker_id === null) {
            return null;
        }

        $sticker = $message->relationLoaded('sticker') ? $message->sticker : $message->sticker()->first();

        return $sticker ? [
            'id' => $sticker->id,
            'label' => $sticker->label,
            'url' => $sticker->url,
            'width' => $sticker->width,
            'height' => $sticker->height,
            'is_animated' => $sticker->is_animated,
        ] : null;
    }

    /**
     * ข้อมูลสำหรับหน้าแอดมิน — ทุกชุด ทุกตัว รวมที่ปิดไว้ พร้อมจำนวนครั้งที่ถูกส่ง
     *
     * @return array<int, array<string, mixed>>
     */
    public function adminList(): array
    {
        $usage = ChatMessage::query()
            ->whereNotNull('sticker_id')
            ->groupBy('sticker_id')
            ->selectRaw('sticker_id, count(*) as uses')
            ->pluck('uses', 'sticker_id');

        return StickerPack::query()
            ->with('stickers')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (StickerPack $pack) => [
                'id' => $pack->id,
                'name' => $pack->name,
                'is_active' => $pack->is_active,
                'sort_order' => $pack->sort_order,
                'stickers' => $pack->stickers->map(fn (Sticker $s) => [
                    'id' => $s->id,
                    'label' => $s->label,
                    'keywords' => (string) $s->keywords,
                    'url' => $s->url,
                    'width' => $s->width,
                    'height' => $s->height,
                    'is_animated' => $s->is_animated,
                    'is_active' => $s->is_active,
                    'sort_order' => $s->sort_order,
                    'uses' => (int) ($usage[$s->id] ?? 0),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * เพิ่มสติกเกอร์จากไฟล์ — ป้ายตั้งต้นมาจากชื่อไฟล์ (ตัดนามสกุลและเลขนำหน้า)
     * แอดมินจะได้ตั้งชื่อไฟล์เป็น "01-รับทราบ.png" แล้วอัปทีเดียวจบ
     */
    public function addFromUpload(StickerPack $pack, UploadedFile $file): Sticker
    {
        $stored = $this->store($file);
        $nextOrder = (int) Sticker::where('pack_id', $pack->id)->max('sort_order') + 1;

        try {
            return Sticker::create([
                'pack_id' => $pack->id,
                'label' => $this->labelFromFilename($file->getClientOriginalName()),
                'image_path' => $stored['path'],
                'width' => $stored['width'],
                'height' => $stored['height'],
                'is_animated' => $stored['animated'],
                'sort_order' => $nextOrder,
            ]);
        } catch (\Throwable $e) {
            Storage::disk(MediaDisk::name())->delete($stored['path']);

            throw $e;
        }
    }

    /** เปลี่ยนรูปของสติกเกอร์เดิม — ข้อความเก่าที่ใช้ตัวนี้เปลี่ยนรูปตามไปด้วย */
    public function replaceImage(Sticker $sticker, UploadedFile $file): Sticker
    {
        $stored = $this->store($file);
        $old = $sticker->image_path;

        $sticker->forceFill([
            'image_path' => $stored['path'],
            'width' => $stored['width'],
            'height' => $stored['height'],
            'is_animated' => $stored['animated'],
        ])->save();

        if ($old && $old !== $stored['path']) {
            Storage::disk(MediaDisk::name())->delete($old);
        }

        return $sticker;
    }

    /**
     * ลบสติกเกอร์ — ข้อความที่เคยส่งตัวนี้เหลือแค่ข้อความสำรอง (FK nullOnDelete)
     * ตั้ง null เองก่อนด้วย เผื่อฐานข้อมูลที่ไม่บังคับ FK
     */
    public function delete(Sticker $sticker): void
    {
        DB::transaction(function () use ($sticker) {
            ChatMessage::where('sticker_id', $sticker->id)->update(['sticker_id' => null]);
            $sticker->delete();
        });

        Storage::disk(MediaDisk::name())->delete($sticker->image_path);
    }

    public function deletePack(StickerPack $pack): void
    {
        foreach ($pack->stickers()->get() as $sticker) {
            $this->delete($sticker);
        }

        $pack->delete();
    }

    /**
     * จัดลำดับตาม id ที่ส่งมา — id ที่ไม่อยู่ในขอบเขต (ชุดอื่น) ถูกข้ามไป
     *
     * @param  array<int, int>  $ids
     */
    public function reorder(string $model, array $ids, ?int $packId = null): void
    {
        DB::transaction(function () use ($model, $ids, $packId) {
            foreach (array_values($ids) as $i => $id) {
                $model::query()
                    ->whereKey((int) $id)
                    ->when($packId !== null, fn ($q) => $q->where('pack_id', $packId))
                    ->update(['sort_order' => $i + 1]);
            }
        });
    }

    /**
     * "01-รับทราบ.png" → "รับทราบ", "ok_thanks.webp" → "ok thanks"
     */
    public function labelFromFilename(string $filename): string
    {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/^\d+[\s._-]*/u', '', $name) ?? $name;
        $name = trim(preg_replace('/[_\s]+/u', ' ', $name) ?? $name);

        return Str::limit($name !== '' ? $name : 'สติกเกอร์', 40, '');
    }

    /**
     * เก็บไฟล์ลงดิสก์สื่อ — ภาพนิ่งที่ใหญ่เกินถูกย่อเป็น PNG พื้นใส
     *
     * @return array{path: string, width: ?int, height: ?int, animated: bool}
     */
    private function store(UploadedFile $file): array
    {
        $source = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'png');
        $info = @getimagesize($source);
        $width = $info[0] ?? null;
        $height = $info[1] ?? null;
        $animated = $this->isAnimated($source, $info[2] ?? null);
        $dir = 'stickers/'.date('Y/m');
        $disk = Storage::disk(MediaDisk::name());

        if (! $animated && $width && $height && max($width, $height) > self::MAX_EDGE) {
            $resized = $this->downscale($source, $info[2] ?? null, $width, $height);

            if ($resized !== null) {
                $path = $dir.'/'.Str::random(24).'.png';
                $disk->put($path, $resized['bytes'], 'public');

                return ['path' => $path, 'width' => $resized['width'], 'height' => $resized['height'], 'animated' => false];
            }
        }

        $path = $file->storeAs($dir, Str::random(24).'.'.$extension, MediaDisk::name());

        return ['path' => $path, 'width' => $width, 'height' => $height, 'animated' => $animated];
    }

    /**
     * GIF หลายเฟรม หรือ WebP ที่มีชังก์ ANIM — ย่อด้วย GD ไม่ได้ (เหลือเฟรมเดียว)
     */
    public function isAnimated(string $path, ?int $type): bool
    {
        $bytes = @file_get_contents($path, false, null, 0, 1024 * 1024) ?: '';

        return match ($type) {
            IMAGETYPE_GIF => substr_count($bytes, "\x21\xF9\x04") > 1,
            // WebP เคลื่อนไหวต้องเป็นแบบขยาย (VP8X) และมีชังก์ ANIM/ANMF ต่อท้าย
            IMAGETYPE_WEBP => str_contains(substr($bytes, 0, 16), 'VP8X')
                && (str_contains($bytes, 'ANIM') || str_contains($bytes, 'ANMF')),
            default => false,
        };
    }

    /**
     * @return array{bytes: string, width: int, height: int}|null
     */
    private function downscale(string $source, ?int $type, int $width, int $height): ?array
    {
        $src = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            default => false,
        };

        if (! $src) {
            return null;
        }

        $scale = self::MAX_EDGE / max($width, $height);
        $tw = max(1, (int) round($width * $scale));
        $th = max(1, (int) round($height * $scale));

        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $width, $height);

        ob_start();
        imagepng($dst, null, 8);
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return $bytes ? ['bytes' => $bytes, 'width' => $tw, 'height' => $th] : null;
    }
}
