<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\FieldSchema;
use App\Http\Controllers\Api\Support\Reorders;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Plugins\ModuleRegistry;
use App\Support\Fields;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends ApiController
{
    use Reorders;

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function index(Request $request)
    {
        $query = Product::with('prices', 'group', 'meters.prices')->orderBy('sort')->orderBy('name');
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('product_group_id')) {
            $query->where('product_group_id', $request->integer('product_group_id'));
        }
        if ($q = $request->query('q')) {
            $query->whereContains('name', $q);
        }

        return $this->paginate($query, ProductResource::class, $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $product = DB::transaction(function () use ($data) {
            $product = Product::create($this->attributes($data) + ['slug' => $data['slug'] ?? $this->slug($data['name'])]);
            $this->syncPrices($product, $data['prices'] ?? []);

            return $product;
        });
        $this->audit('product.created', $product);

        return (new ProductResource($product->load('prices', 'group', 'meters.prices')))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load('prices', 'group', 'meters.prices'));
    }

    public function update(Request $request, Product $product): ProductResource
    {
        $data = $request->validate($this->rules($product));
        DB::transaction(function () use ($data, $product) {
            $product->update($this->attributes($data, $product) + (isset($data['slug']) ? ['slug' => $data['slug']] : []));
            if (array_key_exists('prices', $data)) {
                $this->syncPrices($product, $data['prices'] ?? []);
            }
        });
        $this->audit('product.updated', $product);

        return new ProductResource($product->refresh()->load('prices', 'group', 'meters.prices'));
    }

    public function destroy(Request $request, Product $product)
    {
        $this->requireAdmin($request, 'Only administrators can delete products.');
        if ($product->services()->whereIn('status', ['active', 'suspended', 'pending'])->exists()) {
            throw new \DomainException('This product has live services and cannot be deleted. Deactivate it instead.');
        }
        $this->audit('product.deleted', $product);
        $product->delete();

        return response()->noContent();
    }

    /** Set the display order: send every product id (or just the ones to move to the front) in the order you want. */
    public function reorder(Request $request)
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct', 'exists:products,id']]);
        $this->applyOrder(Product::class, $data['ids']);
        $this->audit('product.reordered', null, 'Reordered products');

        return ProductResource::collection(Product::with('prices', 'group', 'meters.prices')->orderBy('sort')->orderBy('name')->get());
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(?Product $product = null): array
    {
        $s = $product ? 'sometimes' : 'required';

        return [
            'name' => [$s, 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'product_group_id' => ['nullable', 'integer', 'exists:product_groups,id'],
            'module' => ['nullable', 'string', Rule::in(array_keys($this->modules->options()))],
            'module_config' => ['nullable', 'array'],
            'label_prompt' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'is_visible' => ['nullable', 'boolean'],
            'tax_exempt' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'trial_requires_payment_method' => ['sometimes', 'boolean'],
            'allow_plan_changes' => ['sometimes', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*.cycle' => ['required', Rule::enum(BillingCycle::class)],
            'prices.*.currency' => ['required', 'string', 'size:3', fn ($a, $v, $fail) => isset(Money::currencies()[strtoupper((string) $v)]) || $fail('The selected currency is invalid.')],
            'prices.*.amount' => ['required', 'integer', 'min:0'],
            'prices.*.setup_fee' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Columns to write. Module settings are validated with the module's own field list (the same as the admin form) and
     * merged into what is already stored when the module did not change.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Product $existing = null): array
    {
        $out = collect($data)->only(['name', 'description', 'product_group_id', 'label_prompt', 'is_active', 'is_visible', 'tax_exempt', 'sort', 'trial_days', 'trial_requires_payment_method', 'allow_plan_changes'])->all();

        $moduleKey = array_key_exists('module', $data) ? $data['module'] : $existing?->module;
        $module = $this->modules->get($moduleKey);
        if (array_key_exists('module', $data)) {
            $out['module'] = $module?->key();
            if (! $module) {
                $out['module_config'] = null;
            }
        }
        if ($module && (array_key_exists('module_config', $data) || array_key_exists('module', $data))) {
            $fields = $module->productFields();
            $input = $data['module_config'] ?? [];
            FieldSchema::rejectUnknown($fields, $input, 'module_config');
            $this->validateFields($fields, $input, 'module_config', $existing?->module === $module->key() ? ($existing->module_config ?? []) : []);
            $out['module_config'] = array_merge($existing?->module === $module->key() ? ($existing->module_config ?? []) : [], FieldSchema::collectPresent($fields, $input)) ?: null;
        }

        return $out;
    }

    /** Validate the sent module fields; required ones must be present either now or already stored. */
    private function validateFields(array $fields, array $input, string $attribute, array $stored): void
    {
        $rules = Fields::rules(array_values(array_filter($fields, fn ($f) => array_key_exists($f['key'], $input) || (! empty($f['required']) && ! isset($stored[$f['key']])))), "$attribute.");
        validator([$attribute => $input], $rules)->validate();
    }

    private function slug(string $value): string
    {
        $base = Str::slug($value) ?: 'product';
        $slug = $base;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    /** @param  array<int, array<string, mixed>>  $prices */
    private function syncPrices(Product $product, array $prices): void
    {
        $seen = [];
        foreach ($prices as $p) {
            $key = $p['cycle'].'|'.strtoupper($p['currency']);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['prices' => 'Each billing cycle can only have one price per currency.']);
            }
            $seen[$key] = true;
        }
        $product->prices()->delete();
        foreach ($prices as $p) {
            $product->prices()->create([
                'cycle' => $p['cycle'], 'currency' => strtoupper($p['currency']), 'amount' => $p['amount'], 'setup_fee' => $p['setup_fee'] ?? 0,
            ]);
        }
    }
}
