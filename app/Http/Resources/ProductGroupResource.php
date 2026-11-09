<?php

namespace App\Http\Resources;

use App\Models\ProductGroup;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProductGroup */
class ProductGroupResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sort' => (int) $this->sort,
            'is_visible' => $this->is_visible,
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
