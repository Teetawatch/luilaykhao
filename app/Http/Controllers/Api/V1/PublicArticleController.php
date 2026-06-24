<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleListResource;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use App\Models\ArticleCategory;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only article API consumed by the customer app. Mirrors what the
 * Blade blog renders on the web, so the app shows the same content + funnel.
 */
class PublicArticleController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $articles = Article::published()
            ->with('category')
            ->when($request->query('category'), fn ($q, $slug) => $q->whereHas(
                'category', fn ($c) => $c->where('slug', $slug)
            ))
            ->when($request->query('tag'), fn ($q, $slug) => $q->whereHas(
                'tags', fn ($t) => $t->where('slug', $slug)
            ))
            ->orderByDesc('published_at')
            ->paginate(12);

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

    public function categories(): JsonResponse
    {
        $categories = ArticleCategory::withCount(['articles' => fn ($q) => $q->published()])
            ->whereHas('articles', fn ($q) => $q->published())
            ->orderBy('name')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'articles_count' => $c->articles_count,
            ]);

        return $this->success($categories);
    }

    public function show(string $slug): JsonResponse
    {
        $article = Article::published()
            ->with(['category', 'author', 'tags', 'trips.schedules.pickupPoints'])
            ->where('slug', $slug)
            ->firstOrFail();

        $article->increment('views');

        return $this->success(new ArticleResource($article));
    }
}
