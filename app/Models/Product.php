<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Support\Settings;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'module_config' => 'array',
            'is_active' => 'boolean',
            'is_visible' => 'boolean',
            'tax_exempt' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function meters(): HasMany
    {
        return $this->hasMany(UsageMeter::class)->orderBy('sort')->orderBy('id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /** The price for a cycle in the client's currency, falling back to the store currency. */
    public function priceFor(BillingCycle $cycle, ?string $currency = null): ?ProductPrice
    {
        $currency ??= Settings::get('billing.currency', config('billing.currency'));
        $prices = $this->relationLoaded('prices') ? $this->prices : $this->prices()->get();

        return $prices->first(fn (ProductPrice $p) => $p->cycle === $cycle && $p->currency === $currency)
            ?? $prices->first(fn (ProductPrice $p) => $p->cycle === $cycle && $p->currency === Settings::get('billing.currency', config('billing.currency')));
    }

    /** @return Collection<int, ProductPrice> prices in a currency, cheapest cycle first */
    public function pricesIn(string $currency)
    {
        return $this->prices->where('currency', $currency)->sortBy(fn (ProductPrice $p) => $p->cycle->months())->values();
    }

    public function moduleOption(string $key, mixed $default = null): mixed
    {
        return data_get($this->module_config, $key, $default);
    }
}
