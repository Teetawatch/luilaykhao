<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Sticker;
use App\Models\StickerPack;
use App\Services\StickerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * สติกเกอร์ของแบรนด์ — แอปดึงชุดที่เปิดอยู่ไปวาดแผงสติกเกอร์ แอดมินจัดการที่ /admin/stickers
 */
class StickerController extends Controller
{
    use ApiResponse;

    public function __construct(private StickerService $stickers) {}

    /** GET chat/stickers — ชุดที่เปิดอยู่พร้อมสติกเกอร์ที่เปิดอยู่ */
    public function index(): JsonResponse
    {
        return $this->success(['packs' => $this->stickers->catalog()]);
    }

    // ── หลังบ้าน ──────────────────────────────────────────────────────────

    public function adminIndex(): JsonResponse
    {
        return $this->success(['packs' => $this->stickers->adminList()]);
    }

    public function storePack(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $pack = StickerPack::create([
            'name' => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => (int) StickerPack::max('sort_order') + 1,
        ]);

        return $this->success(['id' => $pack->id], 'สร้างชุดสติกเกอร์แล้ว', 201);
    }

    public function updatePack(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $pack = StickerPack::findOrFail($id);
        if (isset($validated['name'])) {
            $validated['name'] = trim($validated['name']);
        }
        $pack->update($validated);

        return $this->success(null, 'บันทึกชุดสติกเกอร์แล้ว');
    }

    public function destroyPack(int $id): JsonResponse
    {
        $this->stickers->deletePack(StickerPack::findOrFail($id));

        return $this->success(null, 'ลบชุดสติกเกอร์แล้ว');
    }

    public function reorderPacks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $this->stickers->reorder(StickerPack::class, $validated['ids']);

        return $this->success(null, 'จัดลำดับแล้ว');
    }

    /** POST admin/sticker-packs/{id}/stickers — อัปได้หลายไฟล์พร้อมกัน (files[]) */
    public function upload(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.StickerService::MAX_BATCH],
            'files.*' => [
                'required', 'file',
                'mimes:'.implode(',', StickerService::EXTENSIONS),
                'max:'.StickerService::MAX_KB,
            ],
        ], [
            'files.*.mimes' => 'รองรับเฉพาะไฟล์ PNG, WebP, GIF หรือ JPG',
            'files.*.max' => 'ไฟล์สติกเกอร์ต้องไม่เกิน '.(StickerService::MAX_KB / 1024).' MB',
        ]);

        $pack = StickerPack::findOrFail($id);
        $created = [];

        foreach ($request->file('files') as $file) {
            $created[] = $this->stickers->addFromUpload($pack, $file)->id;
        }

        return $this->success(['ids' => $created], 'อัปโหลดสติกเกอร์ '.count($created).' ตัวแล้ว', 201);
    }

    public function updateSticker(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:40'],
            'keywords' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'pack_id' => ['sometimes', 'integer', Rule::exists('sticker_packs', 'id')],
        ]);

        $sticker = Sticker::findOrFail($id);

        if (isset($validated['label'])) {
            $validated['label'] = trim($validated['label']);
        }
        if (array_key_exists('keywords', $validated)) {
            // เก็บแบบสะอาด: "ok , โอเค,, ได้" → "ok,โอเค,ได้"
            $validated['keywords'] = collect(explode(',', (string) $validated['keywords']))
                ->map(fn ($k) => trim($k))
                ->filter()
                ->unique()
                ->implode(',') ?: null;
        }

        $sticker->update($validated);

        return $this->success(null, 'บันทึกสติกเกอร์แล้ว');
    }

    /** POST admin/stickers/{id}/image — เปลี่ยนรูป ข้อความเก่าที่ส่งตัวนี้เปลี่ยนตาม */
    public function replaceImage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file',
                'mimes:'.implode(',', StickerService::EXTENSIONS),
                'max:'.StickerService::MAX_KB,
            ],
        ]);

        $this->stickers->replaceImage(Sticker::findOrFail($id), $request->file('file'));

        return $this->success(null, 'เปลี่ยนรูปสติกเกอร์แล้ว');
    }

    public function destroySticker(int $id): JsonResponse
    {
        $this->stickers->delete(Sticker::findOrFail($id));

        return $this->success(null, 'ลบสติกเกอร์แล้ว');
    }

    public function reorderStickers(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $pack = StickerPack::findOrFail($id);
        $this->stickers->reorder(Sticker::class, $validated['ids'], $pack->id);

        return $this->success(null, 'จัดลำดับแล้ว');
    }
}
