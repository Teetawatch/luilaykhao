<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PartyGameCard;
use App\Models\Trip;
use App\Services\PartyGameService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * เกมรอบวง — แอปดึงคลังไปเก็บในเครื่องก่อนเดินทาง แอดมินจัดการที่ /admin/party-games
 */
class PartyGameController extends Controller
{
    use ApiResponse;

    public function __construct(private PartyGameService $games) {}

    /**
     * GET party-games — รอบที่เปิดเล่นได้ (หรือใกล้เปิด) + คลังเกม
     * ไม่มีรอบไหนเลย = ไม่ส่งคลังให้ (เกมเปิดได้เฉพาะตอนออกทริป)
     */
    public function index(Request $request): JsonResponse
    {
        $rounds = $this->games->roundsFor($request->user());

        return $this->success([
            'today' => now('Asia/Bangkok')->toDateString(),
            'rounds' => $rounds,
            'cards' => $rounds->isEmpty()
                ? []
                : $this->games->catalog($rounds->pluck('trip_id')->unique()->values()->all()),
        ]);
    }

    // ── หลังบ้าน ──────────────────────────────────────────────────────────

    public function adminIndex(): JsonResponse
    {
        $cards = PartyGameCard::with('trip:id,title')
            ->orderBy('game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PartyGameCard $card) => $this->adminPresent($card));

        return $this->success([
            'cards' => $cards,
            'trips' => Trip::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $card = PartyGameCard::create(array_merge(
            $this->games->normalize($request->all()),
            $this->extras($request),
            ['sort_order' => (int) PartyGameCard::max('sort_order') + 1],
        ));

        return $this->success($this->adminPresent($card->load('trip:id,title')), 'เพิ่มเข้าคลังแล้ว', 201);
    }

    /**
     * POST admin/party-games/bulk — วางทีละบรรทัด หนึ่งบรรทัดต่อหนึ่งใบ
     * ใช้กับเกมที่เป็นข้อความล้วน (ความจริง/ท้า, ฉันไม่เคย, คำต้องห้าม, คำใบ้ที่ยังไม่มีคำต้องห้าม)
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game' => ['required', Rule::in(['truth_dare', 'never', 'heads_up', 'forbidden'])],
            'lines' => ['required', 'string', 'max:20000'],
        ]);

        $lines = collect(preg_split('/\r\n|\r|\n/u', $validated['lines']))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->unique()
            ->values();

        if ($lines->isEmpty()) {
            return $this->error('ไม่มีบรรทัดที่มีข้อความ', 422);
        }
        if ($lines->count() > 200) {
            return $this->error('วางได้ครั้งละไม่เกิน 200 บรรทัด', 422);
        }

        // ตรวจทุกบรรทัดก่อน แล้วค่อยบันทึกทีเดียว — ไม่เหลือครึ่ง ๆ กลาง ๆ
        $rows = $lines->map(fn ($line) => $this->games->normalize(array_merge($request->all(), ['body' => $line])));
        $extras = $this->extras($request);
        $order = (int) PartyGameCard::max('sort_order');

        DB::transaction(function () use ($rows, $extras, &$order) {
            foreach ($rows as $row) {
                PartyGameCard::create(array_merge($row, $extras, ['sort_order' => ++$order]));
            }
        });

        return $this->success(['count' => $rows->count()], "เพิ่ม {$rows->count()} ใบเข้าคลังแล้ว", 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $card = PartyGameCard::findOrFail($id);

        // สลับเปิด/ปิดอย่างเดียว ไม่ต้องส่งทั้งใบมา
        if ($request->keys() === ['is_active']) {
            $request->validate(['is_active' => ['required', 'boolean']]);
            $card->update(['is_active' => $request->boolean('is_active')]);
        } else {
            $card->update(array_merge(
                $this->games->normalize($request->all()),
                $this->extras($request, $card->is_active),
            ));
        }

        return $this->success($this->adminPresent($card->fresh('trip:id,title')), 'บันทึกแล้ว');
    }

    public function destroy(int $id): JsonResponse
    {
        PartyGameCard::findOrFail($id)->delete();

        return $this->success(null, 'ลบออกจากคลังแล้ว');
    }

    /**
     * @return array<string, mixed>
     */
    private function extras(Request $request, bool $activeByDefault = true): array
    {
        $validated = $request->validate([
            'trip_id' => ['nullable', 'integer', Rule::exists('trips', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'trip_id' => $validated['trip_id'] ?? null,
            'is_active' => $validated['is_active'] ?? $activeByDefault,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminPresent(PartyGameCard $card): array
    {
        return array_merge($this->games->present($card), [
            'is_active' => (bool) $card->is_active,
            'trip_title' => $card->trip?->title,
            'updated_at' => $card->updated_at?->toIso8601String(),
        ]);
    }
}
