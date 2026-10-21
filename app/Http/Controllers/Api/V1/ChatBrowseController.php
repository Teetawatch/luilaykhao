<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ค้นหาข้อความ + คลังรูป/ลิงก์/ตำแหน่งของห้อง — ลูกค้าหาเบอร์ เวลา หรือรูปที่
 * เพื่อนส่งเมื่อวานได้โดยไม่ต้องไล่เลื่อนทั้งห้อง ผลทุกตัวชี้กลับไปที่ข้อความ
 * (message_id) แอปกระโดดไปดูในบริบทเดิมได้
 *
 * ใช้ตัวกรองเดียวกับการอ่านห้อง (ChatService::scopeVisibleTo) — ข้อความที่ถูกซ่อน
 * หรือของคนที่บล็อกกันไว้ต้องไม่หลุดมาทางนี้
 */
class ChatBrowseController extends Controller
{
    use ApiResponse;

    public const SEARCH_LIMIT = 50;

    public const MEDIA_PAGE = 60;

    public const LINK_PAGE = 40;

    /** ตัวหนังสือรอบคำที่เจอในผลค้นหา (ต่อข้าง) */
    private const EXCERPT_RADIUS = 40;

    private const URL_PATTERN = '~https?://[^\s<>"\']+~u';

    public function __construct(private ChatService $chat) {}

    /** GET schedules/{id}/chat/search?q= — ใหม่สุดก่อน */
    public function search(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        [$schedule, $error] = $this->room($request, $scheduleId);
        if ($error) {
            return $error;
        }

        $q = trim($validated['q']);
        if (mb_strlen($q) < 2) {
            return $this->success(['items' => []]);
        }

        // หนีอักขระพิเศษของ LIKE ด้วย "!" — ใช้ได้ทั้ง MySQL, SQLite และ Postgres
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';

        $messages = ChatMessage::where('schedule_id', $schedule->id)
            ->tap(fn ($query) => $this->chat->scopeVisibleTo($query, $request->user()))
            ->where('is_deleted', false)
            // body ของสติกเกอร์เป็นแค่ข้อความสำรอง ไม่ใช่สิ่งที่คนพิมพ์
            ->whereNull('sticker_id')
            ->whereRaw("body LIKE ? ESCAPE '!'", [$like])
            ->with('user:id,name,nickname')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return $this->success([
            'items' => $messages->map(fn (ChatMessage $m) => [
                'message_id' => $m->id,
                'excerpt' => $this->excerpt((string) $m->body, $q),
                'sender_name' => $this->senderName($m),
                'sender_role' => $m->sender_role,
                'created_at' => $m->created_at?->toISOString(),
            ])->values()->all(),
        ]);
    }

    /**
     * GET schedules/{id}/chat/media?type=images|links&before_id=
     * images = รูปที่ส่งในห้อง · links = ลิงก์ในข้อความ (ตำแหน่งที่แชร์ติดป้าย location)
     */
    public function media(Request $request, int $scheduleId): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['images', 'links'])],
            'before_id' => ['nullable', 'integer', 'min:1'],
        ]);

        [$schedule, $error] = $this->room($request, $scheduleId);
        if ($error) {
            return $error;
        }

        $images = $validated['type'] === 'images';
        $limit = $images ? self::MEDIA_PAGE : self::LINK_PAGE;

        $messages = ChatMessage::where('schedule_id', $schedule->id)
            ->tap(fn ($query) => $this->chat->scopeVisibleTo($query, $request->user()))
            ->where('is_deleted', false)
            ->when($images, fn ($q) => $q->whereNotNull('image_path'))
            ->when(! $images, fn ($q) => $q->whereNull('sticker_id')->where('body', 'like', '%http%'))
            ->when(isset($validated['before_id']), fn ($q) => $q->where('id', '<', $validated['before_id']))
            ->with('user:id,name,nickname')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $items = $images
            ? $messages->map(fn (ChatMessage $m) => [
                'message_id' => $m->id,
                'image_url' => $m->image_url,
                'sender_name' => $this->senderName($m),
                'created_at' => $m->created_at?->toISOString(),
            ])->values()->all()
            : $messages->flatMap(fn (ChatMessage $m) => $this->links($m))->values()->all();

        return $this->success([
            'items' => $items,
            // หน้าถัดไปเริ่มก่อนข้อความเก่าสุดของหน้านี้
            'next_before_id' => $messages->count() === $limit ? $messages->last()->id : null,
        ]);
    }

    /**
     * ลิงก์ทั้งหมดในข้อความหนึ่ง — ตำแหน่งที่แชร์จากแอป ("📍 ตำแหน่งของฉัน" + ลิงก์แผนที่)
     * ติดป้าย location ให้แอปขึ้นไอคอนหมุด
     *
     * @return array<int, array<string, mixed>>
     */
    private function links(ChatMessage $message): array
    {
        $body = (string) $message->body;
        preg_match_all(self::URL_PATTERN, $body, $found);

        // บรรทัดแรกที่ไม่ใช่ลิงก์ = คำอธิบายที่คนส่งพิมพ์ไว้
        $caption = collect(preg_split('/\R/u', $body) ?: [])
            ->map(fn ($line) => trim((string) preg_replace(self::URL_PATTERN, '', $line)))
            ->first(fn ($line) => $line !== '');

        return collect($found[0] ?? [])
            ->map(fn ($url) => rtrim($url, '.,)!?'))
            ->unique()
            ->map(fn ($url) => [
                'message_id' => $message->id,
                'url' => $url,
                'kind' => $this->isMapLink($url) ? 'location' : 'link',
                'title' => $caption ? Str::limit($caption, 80) : (parse_url($url, PHP_URL_HOST) ?: $url),
                'host' => parse_url($url, PHP_URL_HOST),
                'sender_name' => $this->senderName($message),
                'created_at' => $message->created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    private function isMapLink(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return str_contains($host, 'maps.app.goo.gl')
            || (str_contains($host, 'goo.gl') && str_starts_with($path, '/maps'))
            || str_starts_with($host, 'maps.google.')
            || (str_contains($host, 'google.') && str_starts_with($path, '/maps'));
    }

    /** ตัดข้อความรอบคำที่เจอ ให้เห็นบริบทพอรู้ว่าใช่ข้อความที่หาไหม */
    private function excerpt(string $body, string $q): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));
        $pos = mb_stripos($flat, $q);
        if ($pos === false || mb_strlen($flat) <= self::EXCERPT_RADIUS * 2 + mb_strlen($q)) {
            return Str::limit($flat, self::EXCERPT_RADIUS * 2 + 20);
        }

        $start = max(0, $pos - self::EXCERPT_RADIUS);
        $length = mb_strlen($q) + self::EXCERPT_RADIUS * 2;
        $slice = mb_substr($flat, $start, $length);

        return ($start > 0 ? '…' : '').$slice.($start + $length < mb_strlen($flat) ? '…' : '');
    }

    private function senderName(ChatMessage $m): string
    {
        if ($m->sender_role === 'system') {
            return 'ระบบ';
        }

        return $m->user?->nickname ?: ($m->user?->name ?? 'ผู้ใช้');
    }

    /**
     * @return array{0: ?TripSchedule, 1: ?JsonResponse}
     */
    private function room(Request $request, int $scheduleId): array
    {
        $schedule = TripSchedule::findOrFail($scheduleId);

        if (! $this->chat->canAccess($request->user(), $schedule)) {
            return [null, $this->error('คุณไม่มีสิทธิ์เข้าถึงห้องแชทนี้', 403)];
        }

        return [$schedule, null];
    }
}
