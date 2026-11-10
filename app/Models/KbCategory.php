<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A knowledgebase category. Categories nest to any depth; articles live in one category (or at the top level). */
class KbCategory extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['sort' => 0, 'is_visible' => true];

    protected function casts(): array
    {
        return ['is_visible' => 'boolean', 'sort' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(KbArticle::class, 'category_id')->orderBy('sort')->orderBy('title');
    }
}
