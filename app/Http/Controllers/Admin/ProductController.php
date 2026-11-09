<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Plugins\ModuleRegistry;
use App\Services\UsageService;
use App\Support\Activity;
use App\Support\Fields;
use App\Support\MeterInput;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function index()
    {
        return view('admin.products.index', [
            'products' => Product::with(['group', 'prices'])->withCount(['services as live_count' => fn ($q) => $q->whereIn('status', ['active', 'suspended'])])->orderBy('sort')->orderBy('name')->get(),
            'groups' => ProductGroup::withCount('products')->orderBy('sort')->orderBy('name')->get(),
            'modules' => $this->modules->options(),
        ]);
    }

    public function create()
    {
        return view('admin.products.form', $this->formData(new Product(['is_active' => true, 'is_visible' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = Product::create($this->payload($request));
            $this->syncPrices($product, $request);
            $this->syncMeters($product, $request);

            return $product;
        });
        Activity::log('product.created', $product);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product created.');
    }

    public function edit(Product $product)
    {
        return view('admin.products.form', $this->formData($product->load('prices')));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product) {
            $product->update($this->payload($request, $product));
            $this->syncPrices($product, $request);
            $this->syncMeters($product, $request);
        });
        Activity::log('product.updated', $product);

        return back()->with('status', 'Product saved.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($product->services()->whereIn('status', ['active', 'suspended', 'pending'])->exists()) {
            return back()->with('error', 'This product has live services and cannot be deleted. Deactivate it instead.');
        }
        Activity::log('product.deleted', $product);
        $product->delete();

        return redirect()->route('admin.products.index')->with('status', 'Product deleted.');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, ?Product $existing = null): array
    {
        $moduleKeys = array_keys($this->modules->options());
        $base = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($existing?->id)],
            'product_group_id' => ['nullable', 'exists:product_groups,id'],
            'description' => ['nullable', 'string', 'max:10000'],
            'label_prompt' => ['nullable', 'string', 'max:255'],
            'module' => ['nullable', Rule::in($moduleKeys)],
        ]);
        $module = $this->modules->get($base['module'] ?? null);
        $config = null;
        if ($module) {
            $fields = $module->productFields();
            $input = $request->validate(Fields::rules($fields, 'module_config.'))['module_config'] ?? [];
            $config = array_merge($existing?->module === $module->key() ? ($existing->module_config ?? []) : [], Fields::collect($fields, $input));
        }

        return [
            'name' => $base['name'],
            'slug' => ($base['slug'] ?? null) ?: $this->uniqueSlug($base['name'], $existing),
            'product_group_id' => $base['product_group_id'] ?? null,
            'description' => $base['description'] ?? null,
            'label_prompt' => $base['label_prompt'] ?? null,
            'module' => $module?->key(),
            'module_config' => $config,
            'is_active' => $request->boolean('is_active'),
            'is_visible' => $request->boolean('is_visible'),
            'tax_exempt' => $request->boolean('tax_exempt'),
        ];
    }

    private function uniqueSlug(string $name, ?Product $existing): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $n = 2;
        while (Product::withTrashed()->where('slug', $slug)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    private function syncPrices(Product $product, Request $request): void
    {
        $rows = $request->validate([
            'prices' => ['nullable', 'array'],
            'prices.*.cycle' => ['required', Rule::enum(BillingCycle::class)],
            'prices.*.currency' => ['required', 'string', 'size:3', Rule::in(array_keys(Money::currencies()))],
            'prices.*.amount' => ['required', 'numeric', 'min:0'],
            'prices.*.setup_fee' => ['nullable', 'numeric', 'min:0'],
        ])['prices'] ?? [];

        $seen = [];
        $records = [];
        foreach ($rows as $row) {
            $key = $row['cycle'].'|'.strtoupper($row['currency']);
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(['prices' => 'Each billing cycle can only have one price per currency.']);
            }
            $seen[$key] = true;
            $cur = strtoupper($row['currency']);
            $records[] = ['cycle' => $row['cycle'], 'currency' => $cur, 'amount' => Money::toMinor($row['amount'], $cur), 'setup_fee' => Money::toMinor($row['setup_fee'] ?? 0, $cur)];
        }
        $product->prices()->delete();
        foreach ($records as $r) {
            $product->prices()->create($r);
        }
    }

    /** Metered usage section; posts without it (meters_present) leave existing meters untouched. */
    private function syncMeters(Product $product, Request $request): void
    {
        if (($rows = MeterInput::fromRequest($request)) !== null) {
            try {
                app(UsageService::class)->syncMeters($product, $rows);
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['meters' => $e->getMessage()]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function formData(Product $product): array
    {
        $default = (string) Settings::get('billing.currency', config('billing.currency'));
        $prices = old('prices') ?? $product->prices->map(fn ($p) => [
            'cycle' => $p->cycle->value, 'currency' => $p->currency, 'amount' => Money::toDecimal($p->amount, $p->currency), 'setup_fee' => Money::toDecimal($p->setup_fee, $p->currency),
        ])->all();

        return [
            'product' => $product,
            'groups' => ['' => 'No group'] + ProductGroup::orderBy('name')->pluck('name', 'id')->all(),
            'modules' => $this->modules->all(),
            'cycles' => BillingCycle::options(),
            'currencies' => Money::currencies(),
            'defaultCurrency' => $default,
            'prices' => array_values($prices),
        ];
    }
}
