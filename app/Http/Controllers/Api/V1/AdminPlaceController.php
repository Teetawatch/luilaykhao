<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Place;
use App\Support\ThaiSlug;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPlaceController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $places = Place::withCount('trips')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success($places);
    }

    public function show(int $id): JsonResponse
    {
        $place = Place::with('trips:id,title')->findOrFail($id);

        return $this->success($place);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request, null);

        $validated['slug'] = ThaiSlug::unique(
            $validated['slug'] ?? $validated['name'],
            fn (string $slug) => Place::where('slug', $slug)->exists(),
            'place',
        );

        if (! isset($validated['sort_order'])) {
            $validated['sort_order'] = (Place::max('sort_order') ?? -1) + 1;
        }

        $tripIds = $validated['trip_ids'] ?? null;
        unset($validated['trip_ids']);

        $place = Place::create($validated);

        if ($tripIds !== null) {
            $place->trips()->sync($tripIds);
        }

        return $this->success($place->fresh('trips'), 'สร้างสถานที่สำเร็จ', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $place = Place::findOrFail($id);
        $validated = $this->validated($request, $id);

        if (array_key_exists('slug', $validated)) {
            $validated['slug'] = ThaiSlug::unique(
                $validated['slug'] ?: $place->name,
                fn (string $slug) => Place::where('slug', $slug)->where('id', '!=', $id)->exists(),
                'place',
            );
        }

        $tripIds = $validated['trip_ids'] ?? null;
        unset($validated['trip_ids']);

        $place->update($validated);

        if ($tripIds !== null) {
            $place->trips()->sync($tripIds);
        }

        return $this->success($place->fresh('trips'), 'อัปเดตสถานที่สำเร็จ');
    }

    public function destroy(int $id): JsonResponse
    {
        Place::findOrFail($id)->delete();

        return $this->success(null, 'ลบสถานที่สำเร็จ');
    }

    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->input('ids') as $order => $id) {
            Place::where('id', $id)->update(['sort_order' => $order]);
        }

        return $this->success(null, 'จัดเรียงสถานที่สำเร็จ');
    }

    /**
     * กฎเดียวกันทั้งสร้างและแก้ไข ต่างแค่ตอนสร้างต้องมีชื่อ
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $id): array
    {
        $required = $id === null ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', Rule::in(array_keys(Place::TYPES))],
            'region' => ['nullable', Rule::in(array_keys(Place::REGIONS))],
            'province' => ['nullable', 'string', 'max:80'],
            'park' => ['nullable', 'string', 'max:160'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'elevation_m' => ['nullable', 'integer', 'min:0', 'max:9000'],
            'trail_distance_km' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'elevation_gain_m' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'difficulty' => ['nullable', Rule::in(array_keys(Place::DIFFICULTIES))],

            'best_months' => ['nullable', 'array', 'max:12'],
            'best_months.*' => ['integer', 'between:1,12'],
            'closed_months' => ['nullable', 'array', 'max:12'],
            'closed_months.*' => ['integer', 'between:1,12'],
            'season_note' => ['nullable', 'string', 'max:2000'],
            'closure_note' => ['nullable', 'string', 'max:500'],

            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'highlights' => ['nullable', 'array', 'max:20'],
            'highlights.*' => ['string', 'max:200'],
            'know_before' => ['nullable', 'array', 'max:20'],
            'know_before.*' => ['string', 'max:300'],

            'cover_image' => ['nullable', 'string', 'max:2048'],
            'gallery' => ['nullable', 'array', 'max:30'],
            'gallery.*' => ['string', 'max:2048'],

            'status' => ['nullable', Rule::in(['draft', 'published'])],
            'sort_order' => ['nullable', 'integer'],

            'trip_ids' => ['nullable', 'array'],
            'trip_ids.*' => ['integer', 'exists:trips,id'],
        ]);
    }
}
