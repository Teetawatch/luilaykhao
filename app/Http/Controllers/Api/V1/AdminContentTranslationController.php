<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\TranslateContentJob;
use App\Services\ContentTranslationService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * แท็บ "English" ในหน้าแก้ไขทริป (และเนื้อหาอื่นที่แปลได้) — ดูคำแปลข้างต้นฉบับ
 * แก้ทีละช่อง ปลดล็อกให้ AI แปลใหม่ หรือสั่งแปลเดี๋ยวนี้
 */
class AdminContentTranslationController extends Controller
{
    use ApiResponse;

    public function __construct(private ContentTranslationService $translations) {}

    public function show(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->model($type, $id);
        $locale = $this->locale($request);
        $items = $this->translations->overview($model, $locale);

        return $this->success([
            'type' => $type,
            'id' => $id,
            'locale' => $locale,
            'ai_ready' => ContentTranslationService::aiReady(),
            'counts' => collect($items)->countBy('status')->all() + ['total' => count($items)],
            'items' => $items,
        ]);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:191'],
            'text' => ['required', 'string', 'max:20000'],
            'locale' => ['nullable', Rule::in(array_keys(ContentTranslationService::LANGUAGES))],
        ]);

        try {
            $this->translations->saveManual(
                $this->model($type, $id),
                $data['path'],
                $data['text'],
                $request->user(),
                $data['locale'] ?? 'en',
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->show($request, $type, $id);
    }

    /** ปลดล็อกช่องที่แก้เอง แล้วให้ AI แปลช่องนั้นใหม่ */
    public function unlock(Request $request, string $type, int $id): JsonResponse
    {
        $data = $request->validate(['path' => ['required', 'string', 'max:191']]);
        $model = $this->model($type, $id);

        $this->translations->unlock($model, $data['path'], $this->locale($request));
        TranslateContentJob::dispatchFor($model);

        return $this->show($request, $type, $id);
    }

    /** แปลช่องที่ยังขาดเดี๋ยวนี้ (หรือทั้งหมดใหม่ด้วย force) — ช่องที่แก้มือไม่ถูกแตะ */
    public function refresh(Request $request, string $type, int $id): JsonResponse
    {
        if (! ContentTranslationService::aiReady()) {
            return $this->error('ยังไม่ได้ตั้งค่า ANTHROPIC_API_KEY ระบบจึงแปลอัตโนมัติไม่ได้ — แก้คำแปลเองได้ทีละช่อง', 422);
        }

        TranslateContentJob::dispatchFor($this->model($type, $id), $request->boolean('force'));

        return $this->success(['queued' => true], 'ส่งเข้าคิวแปลแล้ว รอสักครู่แล้วกดรีเฟรช');
    }

    private function model(string $type, int $id): Model
    {
        abort_unless(isset(ContentTranslationService::TYPES[$type]), 404);

        return $this->translations->find($type, $id) ?? abort(404);
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->input('locale', 'en');

        return isset(ContentTranslationService::LANGUAGES[$locale]) ? $locale : 'en';
    }
}
