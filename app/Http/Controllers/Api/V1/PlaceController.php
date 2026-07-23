<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Services\PlacePresenter;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สถานที่แบบสาธารณะ — หน้าที่คนเข้ามาหาข้อมูล "ที่นั่นเป็นยังไง" ไม่ใช่หน้าขายทริป
 */
class PlaceController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'region' => ['nullable', 'string', 'max:32'],
            'type' => ['nullable', 'string', 'max:32'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'difficulty' => ['nullable', 'string', 'max:16'],
        ]);

        $places = Place::published()
            ->when($validated['region'] ?? null, fn ($q, $region) => $q->where('region', $region))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($validated['difficulty'] ?? null, fn ($q, $d) => $q->where('difficulty', $d))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // กรองเดือนในหน่วยความจำ เพราะ best_months เป็น JSON และจำนวนสถานที่มีไม่มาก
        // cast เป็น int ก่อน — validate 'integer' ยอมรับสตริงตัวเลข แต่คืนค่าเดิมมาเป็นสตริง
        // ซึ่งจะไม่ตรงกับตัวเลขใน best_months ตอนเทียบแบบ strict
        if ($month = (int) ($validated['month'] ?? 0)) {
            $places = $places->filter(
                fn (Place $place) => in_array($month, $place->best_months ?? [], true)
                    && ! $place->isClosedIn($month),
            );
        }

        return $this->success([
            'places' => $places->map(fn (Place $p) => PlacePresenter::card($p))->values()->all(),
            'filters' => [
                'regions' => self::options(Place::REGIONS),
                'types' => self::options(Place::TYPES),
                'difficulties' => self::options(Place::DIFFICULTIES),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $place = Place::published()->where('slug', $slug)->firstOrFail();

        // นับผู้ชมแบบหลวม ๆ พอให้รู้ว่าหน้าไหนมีคนสนใจ ไม่ต้องแม่นระดับ analytics
        $place->increment('views_count');

        return $this->success(PlacePresenter::detail($place));
    }

    /**
     * ปฏิทิน "เดือนไหนไปไหนดี" — คืนครบ 12 เดือนเสมอ เดือนที่ไม่มีข้อมูลจะได้
     * ลิสต์ว่าง ฝั่งเว็บจะได้วาดปฏิทินเต็มปีโดยไม่ต้องเดาเอง
     */
    public function seasons(): JsonResponse
    {
        $places = Place::published()->orderBy('name')->get();

        $months = [];

        foreach (range(1, 12) as $month) {
            $best = $places->filter(
                fn (Place $p) => in_array($month, $p->best_months ?? [], true) && ! $p->isClosedIn($month),
            );

            $closed = $places->filter(fn (Place $p) => $p->isClosedIn($month));

            $months[] = [
                'month' => $month,
                'label' => self::MONTH_LABELS[$month - 1],
                'season' => self::seasonOf($month),
                'season_label' => self::SEASON_LABELS[self::seasonOf($month)],
                'best' => $best->map(fn (Place $p) => PlacePresenter::card($p))->values()->all(),
                'closed' => $closed->map(fn (Place $p) => [
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'closure_note' => $p->closure_note,
                ])->values()->all(),
            ];
        }

        return $this->success([
            'months' => $months,
            'current_month' => (int) now('Asia/Bangkok')->format('n'),
        ]);
    }

    private const MONTH_LABELS = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
        'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
    ];

    private const SEASON_LABELS = [
        'winter' => 'หน้าหนาว',
        'summer' => 'หน้าร้อน',
        'rainy' => 'หน้าฝน',
    ];

    /** ฤดูแบบไทย: พ.ย.–ก.พ. หนาว, มี.ค.–พ.ค. ร้อน, มิ.ย.–ต.ค. ฝน */
    private static function seasonOf(int $month): string
    {
        return match (true) {
            in_array($month, [11, 12, 1, 2], true) => 'winter',
            in_array($month, [3, 4, 5], true) => 'summer',
            default => 'rainy',
        };
    }

    /** @param array<string, string> $map */
    private static function options(array $map): array
    {
        return collect($map)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }
}
