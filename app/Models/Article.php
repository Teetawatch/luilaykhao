<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Article extends Model
{
    protected $fillable = [
        'title', 'slug', 'excerpt', 'body', 'cover_image_url',
        'category_id', 'status', 'published_at', 'meta_title',
        'meta_description', 'author_id', 'reading_minutes', 'views',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'reading_minutes' => 'integer',
        'views' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'article_tag');
    }

    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'article_trip');
    }

    /** Visible to the public: published and past its publish time. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
    }
}
