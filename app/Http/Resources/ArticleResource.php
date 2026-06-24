<?php

namespace App\Http\Resources;

use App\Support\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full article payload (detail view) — includes the sanitized HTML body plus
 * related trips so the app can render the same booking funnel as the web.
 */
class ArticleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            'cover_image_url' => MediaDisk::hostRelative($this->cover_image_url),
            'reading_minutes' => $this->reading_minutes,
            'views' => $this->views,
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'author' => $this->whenLoaded('author', fn () => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'avatar' => $this->author->avatar ?? null,
            ] : null),
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ])->values()),
            'trips' => $this->whenLoaded('trips', fn () => TripResource::collection($this->trips)),
        ];
    }
}
