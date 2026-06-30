<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    use ApiResponse;

    /**
     * Display a listing of active categories for public.
     */
    public function index(): JsonResponse
    {
        $categories = Category::where('is_active', true)
            ->withCount([
                'trips as trips_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->orderBy('order')
            ->orderBy('name')
            ->get();

        return $this->success($categories);
    }

    /**
     * Display a listing of all categories for admin.
     */
    public function adminIndex(): JsonResponse
    {
        $categories = Category::orderBy('order')->orderBy('name')->get();

        return $this->success($categories);
    }

    /**
     * Store a newly created category in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'display_title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'cta_text' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'icon' => 'nullable|string|max:255',
            'image_url' => 'nullable|string|max:2048',
            'color' => 'nullable|string|max:32',
            'bg_color' => 'nullable|string|max:32',
            'is_popular' => 'sometimes|boolean',
            'order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        if (! isset($validated['order'])) {
            $validated['order'] = (Category::max('order') ?? -1) + 1;
        }

        $category = Category::create($validated);

        return $this->success($category, 'สร้างหมวดหมู่สำเร็จ', 201);
    }

    /**
     * Update the specified category in storage.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'display_title' => 'nullable|string|max:255',
            'subtitle' => 'nullable|string|max:500',
            'cta_text' => 'nullable|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:categories,slug,'.$id,
            'icon' => 'nullable|string|max:255',
            'image_url' => 'nullable|string|max:2048',
            'color' => 'nullable|string|max:32',
            'bg_color' => 'nullable|string|max:32',
            'is_popular' => 'sometimes|boolean',
            'order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($validated);

        return $this->success($category, 'อัปเดตหมวดหมู่สำเร็จ');
    }

    /**
     * Persist a new display order for categories.
     */
    public function reorder(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $order => $id) {
            Category::where('id', $id)->update(['order' => $order]);
        }

        return $this->success(null, 'จัดเรียงหมวดหมู่สำเร็จ');
    }

    /**
     * Remove the specified category from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        // Check if any trips are using this category (optional, but good practice)
        // For now, just delete
        $category->delete();

        return $this->success(null, 'ลบหมวดหมู่สำเร็จ');
    }
}
