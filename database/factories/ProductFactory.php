<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['name' => ucfirst($name), 'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999), 'description' => fake()->sentence(), 'is_active' => true, 'is_visible' => true];
    }

    /** Give the product a price for a cycle, in minor units. */
    public function priced(int $amount = 1000, BillingCycle $cycle = BillingCycle::Monthly, int $setup = 0, string $currency = 'USD'): static
    {
        return $this->afterCreating(fn (Product $p) => $p->prices()->create(['cycle' => $cycle, 'currency' => $currency, 'amount' => $amount, 'setup_fee' => $setup]));
    }

    public function module(string $key, array $config = []): static
    {
        return $this->state(['module' => $key, 'module_config' => $config]);
    }
}
