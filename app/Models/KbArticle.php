<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A knowledgebase article written in Markdown. */
class KbArticle extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'sort' => 0];

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'sort' => 'integer', 'views' => 'integer', 'helpful_yes' => 'integer', 'helpful_no' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
