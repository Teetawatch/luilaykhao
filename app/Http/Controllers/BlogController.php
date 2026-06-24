<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Server-rendered (Blade) blog — the SEO surface. Unlike the Vue SPA, these
 * pages ship full HTML + Article JSON-LD so Google indexes the content, then
 * funnels readers into the SPA booking flow via related-trip CTAs.
 */
class BlogController extends Controller
{
    private const PER_PAGE = 12;

    public function index(Request $request): View
    {
        $articles = Article::published()
            ->with('category')
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE);

        return view('blog.list', [
            'heading' => 'บทความและคำแนะนำเที่ยว',
            'subheading' => 'รวมทริค เตรียมตัว และไอเดียเที่ยวธรรมชาติทั่วไทยจากทีมลุยเลเขา',
            'articles' => $articles,
            'categories' => $this->sidebarCategories(),
            'canonical' => url('/blog'),
            'metaTitle' => 'บทความเที่ยวธรรมชาติ เดินป่า ดำน้ำ | ลุยเลเขา',
            'metaDescription' => 'คู่มือและคำแนะนำเที่ยวธรรมชาติทั่วไทย เดินป่า ดำน้ำตื้น เตรียมตัวก่อนออกทริป พร้อมทริปแนะนำให้จองได้ทันที',
        ]);
    }

    public function category(string $slug): View
    {
        $category = ArticleCategory::where('slug', $slug)->firstOrFail();

        $articles = Article::published()
            ->with('category')
            ->where('category_id', $category->id)
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE);

        return view('blog.list', [
            'heading' => $category->name,
            'subheading' => $category->description ?: "บทความหมวด {$category->name}",
            'articles' => $articles,
            'categories' => $this->sidebarCategories(),
            'activeCategory' => $category->slug,
            'canonical' => url('/blog/category/'.$category->slug),
            'metaTitle' => "{$category->name} | บทความลุยเลเขา",
            'metaDescription' => $category->description ?: "รวมบทความหมวด {$category->name} จากลุยเลเขา",
        ]);
    }

    public function tag(string $slug): View
    {
        $tag = Tag::where('slug', $slug)->firstOrFail();

        $articles = Article::published()
            ->with('category')
            ->whereHas('tags', fn ($q) => $q->where('tags.id', $tag->id))
            ->orderByDesc('published_at')
            ->paginate(self::PER_PAGE);

        return view('blog.list', [
            'heading' => "#{$tag->name}",
            'subheading' => "บทความที่แท็ก {$tag->name}",
            'articles' => $articles,
            'categories' => $this->sidebarCategories(),
            'canonical' => url('/blog/tag/'.$tag->slug),
            'metaTitle' => "{$tag->name} | บทความลุยเลเขา",
            'metaDescription' => "รวมบทความเกี่ยวกับ {$tag->name} จากลุยเลเขา",
        ]);
    }

    public function show(string $slug): View
    {
        $article = Article::published()
            ->with(['category', 'author', 'tags', 'trips'])
            ->where('slug', $slug)
            ->firstOrFail();

        $article->increment('views');

        $related = Article::published()
            ->where('id', '!=', $article->id)
            ->when($article->category_id, fn ($q) => $q->where('category_id', $article->category_id))
            ->orderByDesc('published_at')
            ->limit(3)
            ->get();

        return view('blog.show', [
            'article' => $article,
            'related' => $related,
            'canonical' => url('/blog/'.$article->slug),
            'jsonLd' => $this->articleJsonLd($article),
            'breadcrumbJsonLd' => $this->breadcrumbJsonLd($article),
        ]);
    }

    private function sidebarCategories()
    {
        return ArticleCategory::withCount(['articles' => fn ($q) => $q->published()])
            ->whereHas('articles', fn ($q) => $q->published())
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    private function articleJsonLd(Article $article): array
    {
        $image = $article->cover_image_url ?: asset('images/logo.png');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article->meta_title ?: $article->title,
            'description' => $article->meta_description ?: $article->excerpt,
            'image' => [$image],
            'datePublished' => $article->published_at?->toIso8601String(),
            'dateModified' => $article->updated_at?->toIso8601String(),
            'author' => [
                '@type' => $article->author ? 'Person' : 'Organization',
                'name' => $article->author->name ?? 'ลุยเลเขา',
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => 'ลุยเลเขา Luilaykhao',
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('images/logo.png'),
                ],
            ],
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => url('/blog/'.$article->slug),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function breadcrumbJsonLd(Article $article): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'หน้าแรก', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'บทความ', 'item' => url('/blog')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $article->title, 'item' => url('/blog/'.$article->slug)],
            ],
        ];
    }
}
