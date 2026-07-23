<?php

namespace App\Services;

use App\Models\Article;
use App\Models\Tag;
use App\Support\ThaiSlug;
use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

/**
 * Authoring logic for blog articles: sanitizes the rich-text body, keeps slugs
 * unique and SEO-friendly (Thai-aware), computes reading time, and syncs the
 * tag + related-trip relations. Controllers stay thin; this is the source of truth.
 */
class ArticleService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, ?int $authorId = null): Article
    {
        $article = new Article;
        $article->author_id = $authorId;
        $this->fill($article, $data);
        $article->slug = $this->uniqueSlug($data['slug'] ?? $data['title'], null);
        $article->save();

        $this->syncRelations($article, $data);

        return $article->fresh(['category', 'tags', 'trips', 'author']);
    }

    /** @param array<string, mixed> $data */
    public function update(Article $article, array $data): Article
    {
        $this->fill($article, $data);

        if (array_key_exists('slug', $data) || array_key_exists('title', $data)) {
            $article->slug = $this->uniqueSlug($data['slug'] ?? $article->slug ?: $article->title, $article->id);
        }

        $article->save();
        $this->syncRelations($article, $data);

        return $article->fresh(['category', 'tags', 'trips', 'author']);
    }

    /** Flip published state, stamping published_at the first time it goes live. */
    public function setPublished(Article $article, bool $published): Article
    {
        if ($published) {
            $article->status = 'published';
            $article->published_at = $article->published_at ?? now();
        } else {
            $article->status = 'draft';
        }
        $article->save();

        return $article;
    }

    /** @param array<string, mixed> $data */
    private function fill(Article $article, array $data): void
    {
        foreach (['title', 'excerpt', 'cover_image_url', 'category_id', 'meta_title', 'meta_description'] as $key) {
            if (array_key_exists($key, $data)) {
                $article->{$key} = $data[$key];
            }
        }

        if (array_key_exists('body', $data)) {
            // Never trust client HTML — strip scripts/handlers before it can be
            // rendered raw ({!! !!}) in Blade or the app's HTML widget.
            $article->body = Purifier::clean($data['body'] ?? '');
            $article->reading_minutes = $this->readingMinutes($article->body);
        }

        if (array_key_exists('status', $data)) {
            $article->status = $data['status'] === 'published' ? 'published' : 'draft';
            if ($article->status === 'published' && $article->published_at === null) {
                $article->published_at = now();
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function syncRelations(Article $article, array $data): void
    {
        if (array_key_exists('tags', $data)) {
            $article->tags()->sync($this->resolveTagIds($data['tags'] ?? []));
        }

        if (array_key_exists('trip_ids', $data)) {
            $article->trips()->sync($data['trip_ids'] ?? []);
        }
    }

    /**
     * Map a list of tag names to ids, creating tags that don't exist yet.
     *
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    private function resolveTagIds(array $names): array
    {
        return collect($names)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->map(function (string $name) {
                $slug = ThaiSlug::make($name) ?: Str::lower(Str::random(6));
                $tag = Tag::firstOrCreate(['slug' => $slug], ['name' => $name]);

                return $tag->id;
            })
            ->values()
            ->all();
    }

    private function uniqueSlug(string $source, ?int $ignoreId): string
    {
        return ThaiSlug::unique(
            $source,
            fn (string $slug) => Article::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists(),
            'article',
        );
    }

    /** ~400 readable characters per minute — works for spaceless Thai text too. */
    private function readingMinutes(string $html): int
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        return max(1, (int) ceil(mb_strlen($text) / 400));
    }
}
