<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleListResource;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use App\Services\ArticleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminArticleController extends Controller
{
    use ApiResponse;

    public function __construct(private ArticleService $articles) {}

    public function index(Request $request): JsonResponse
    {
        $articles = Article::with('category')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('search'), fn ($q, $term) => $q->where('title', 'like', "%{$term}%"))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => ArticleListResource::collection($articles->items()),
            'message' => 'สำเร็จ',
            'meta' => [
                'current_page' => $articles->currentPage(),
                'last_page' => $articles->lastPage(),
                'per_page' => $articles->perPage(),
                'total' => $articles->total(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $article = Article::with(['category', 'author', 'tags', 'trips'])->findOrFail($id);

        return $this->success(new ArticleResource($article));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateArticle($request);
        $article = $this->articles->create($data, $request->user()?->id);

        return $this->success(new ArticleResource($article), 'สร้างบทความสำเร็จ', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $data = $this->validateArticle($request, $id);
        $article = $this->articles->update($article, $data);

        return $this->success(new ArticleResource($article), 'อัปเดตบทความสำเร็จ');
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $article = Article::findOrFail($id);
        $published = (bool) $request->boolean('published', true);
        $this->articles->setPublished($article, $published);

        return $this->success(
            new ArticleResource($article->fresh(['category', 'tags', 'trips'])),
            $published ? 'เผยแพร่บทความแล้ว' : 'เปลี่ยนเป็นฉบับร่างแล้ว'
        );
    }

    public function destroy(int $id): JsonResponse
    {
        Article::findOrFail($id)->delete();

        return $this->success(null, 'ลบบทความแล้ว');
    }

    // -- Categories ----------------------------------------------------------

    public function categories(): JsonResponse
    {
        return $this->success(
            ArticleCategory::withCount('articles')->orderBy('name')->get()
        );
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $data['slug'] = $this->uniqueCategorySlug($data['name']);

        return $this->success(ArticleCategory::create($data), 'สร้างหมวดหมู่สำเร็จ', 201);
    }

    public function updateCategory(Request $request, int $id): JsonResponse
    {
        $category = ArticleCategory::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $category->update($data);

        return $this->success($category, 'อัปเดตหมวดหมู่สำเร็จ');
    }

    public function destroyCategory(int $id): JsonResponse
    {
        ArticleCategory::findOrFail($id)->delete();

        return $this->success(null, 'ลบหมวดหมู่แล้ว');
    }

    public function tags(): JsonResponse
    {
        return $this->success(Tag::orderBy('name')->get());
    }

    /** @return array<string, mixed> */
    private function validateArticle(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'cover_image_url' => ['nullable', 'string', 'max:2048'],
            'category_id' => ['nullable', 'integer', 'exists:article_categories,id'],
            'status' => ['nullable', 'in:draft,published'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:60'],
            'trip_ids' => ['nullable', 'array'],
            'trip_ids.*' => ['integer', 'exists:trips,id'],
        ]);
    }

    private function uniqueCategorySlug(string $name): string
    {
        $base = trim(mb_strtolower(preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $name) ?? ''), '-');
        if ($base === '') {
            $base = 'cat-'.Str::lower(Str::random(5));
        }
        $slug = $base;
        $i = 2;
        while (ArticleCategory::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
