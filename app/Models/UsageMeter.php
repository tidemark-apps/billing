<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsageMeter extends Model
{
    public const AGGREGATIONS = ['sum' => 'Sum of all records', 'max' => 'Highest value', 'last' => 'Most recent value'];

    public const MODELS = ['per_unit' => 'Per unit', 'tiered' => 'Graduated tiers', 'package' => 'Per package'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['included_quantity' => 'string', 'package_size' => 'string', 'sort' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(UsageMeterPrice::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(UsageRecord::class, 'meter_id');
    }

    /** The price row for a currency, falling back to the store currency (same rule as Product::priceFor). */
    public function priceFor(?string $currency = null): ?UsageMeterPrice
    {
        $store = (string) Settings::get('billing.currency', config('billing.currency'));
        $currency = strtoupper($currency ?: $store);
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        return $prices->first(fn (UsageMeterPrice $p) => $p->currency === $currency) ?? $prices->first(fn (UsageMeterPrice $p) => $p->currency === $store);
    }
}
