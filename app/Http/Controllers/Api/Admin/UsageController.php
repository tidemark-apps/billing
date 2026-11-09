<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UsageMeterResource;
use App\Http\Resources\UsageRecordResource;
use App\Http\Resources\UsageSummaryResource;
use App\Models\Product;
use App\Models\Service;
use App\Models\UsageMeter;
use App\Models\UsageRecord;
use App\Services\UsageService;
use App\Support\Money;
use App\Support\UsageMath;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Metered billing for staff: a product's meters, and the usage recorded against a service. Meters are written through
 * UsageService::syncMeters like the product form, so an already-invoiced meter can never be removed. Prices are integers
 * in micro-minor units (1 = one millionth of the currency's minor unit; 0.002 USD = 200000).
 */
class UsageController extends ApiController
{
    public function __construct(private readonly UsageService $usage) {}

    // ---- meters -------------------------------------------------------------------------------------------------

    public function meters(Product $product)
    {
        return UsageMeterResource::collection($this->usage->meters($product));
    }

    public function showMeter(Product $product, UsageMeter $meter): UsageMeterResource
    {
        return new UsageMeterResource($this->ownMeter($product, $meter)->load('prices'));
    }

    public function storeMeter(Request $request, Product $product)
    {
        $data = $request->validate($this->meterRules());
        $rows = $this->currentRows($product);
        if (collect($rows)->contains('key', $data['key'])) {
            throw ValidationException::withMessages(['key' => 'This product already has a meter with that key.']);
        }
        $rows[] = $this->row($data);
        $this->usage->syncMeters($product, $rows);
        $meter = UsageMeter::with('prices')->where('product_id', $product->id)->where('key', $data['key'])->firstOrFail();
        $this->audit('product.meter_created', $product, "Added meter {$meter->key}");

        return (new UsageMeterResource($meter))->response()->setStatusCode(201);
    }

    /** Partial update. The key cannot change (records are matched to meters by key); sending prices replaces all of the meter's prices. */
    public function updateMeter(Request $request, Product $product, UsageMeter $meter): UsageMeterResource
    {
        $this->ownMeter($product, $meter);
        $data = $request->validate($this->meterRules(false));
        $rows = $this->currentRows($product);
        foreach ($rows as $i => $row) {
            if ($row['key'] === $meter->key) {
                $rows[$i] = $this->row($data, $row);
            }
        }
        $this->usage->syncMeters($product, $rows);
        $this->audit('product.meter_updated', $product, "Updated meter {$meter->key}");

        return new UsageMeterResource($meter->refresh()->load('prices'));
    }

    /** Removes the meter and its usage records, unless any of that usage was invoiced (422). */
    public function destroyMeter(Product $product, UsageMeter $meter)
    {
        $this->ownMeter($product, $meter);
        $rows = array_values(array_filter($this->currentRows($product), fn ($r) => $r['key'] !== $meter->key));
        $this->usage->syncMeters($product, $rows);
        $this->audit('product.meter_deleted', $product, "Deleted meter {$meter->key}");

        return response()->noContent();
    }

    // ---- usage of a service -------------------------------------------------------------------------------------

    /** Current-period summary (`summary`) next to the paginated records, newest first. */
    public function show(Request $request, Service $service)
    {
        $f = $request->validate([
            'meter' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'invoiced' => ['nullable', 'boolean'],
            'source' => ['nullable', 'string', 'max:40'],
        ]);
        $query = UsageRecord::with('meter')->where('service_id', $service->id)->orderByDesc('recorded_at')->orderByDesc('id')
            ->when($f['meter'] ?? null, fn ($q, $v) => $q->whereIn('meter_id', UsageMeter::where('product_id', $service->product_id)->where('key', $v)->select('id')))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('recorded_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('recorded_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v));
        if ($request->has('invoiced') && $request->query('invoiced') !== '') {
            $request->boolean('invoiced') ? $query->whereNotNull('invoiced_at') : $query->whereNull('invoiced_at');
        }

        return $this->paginate($query, UsageRecordResource::class, $request)
            ->additional(['summary' => (new UsageSummaryResource($this->usage->currentPeriodSummary($service->loadMissing('product'))))->resolve($request)]);
    }

    /** Record usage. Idempotent per (service, meter, idempotency_key): a repeat answers 200 with the original record and changes nothing. */
    public function record(Request $request, Service $service)
    {
        $data = $request->validate([
            'meter' => ['required', 'string', 'max:64'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'recorded_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'source' => ['nullable', 'string', 'max:40'],
            'metadata' => ['nullable', 'array', fn ($a, $v, $fail) => strlen((string) json_encode($v)) <= 4000 || $fail('The metadata may not be larger than 4000 characters.')],
        ]);
        try {
            $record = $this->usage->record(
                $service->loadMissing('product'),
                $data['meter'],
                $data['quantity'],
                filled($data['recorded_at'] ?? null) ? Carbon::parse($data['recorded_at']) : null,
                $data['idempotency_key'] ?? null,
                $data['source'] ?? 'api',
                ($data['metadata'] ?? []) + ['user_id' => $request->user()->id],
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([str_contains($e->getMessage(), 'meter') ? 'meter' : 'quantity' => $e->getMessage()]);
        }
        if ($record->wasRecentlyCreated) {
            $this->audit('usage.recorded', $service, 'Recorded '.UsageMath::plain((string) $record->quantity).' of '.$data['meter']);
        }

        return (new UsageRecordResource($record->load('meter')))->response()->setStatusCode($record->wasRecentlyCreated ? 201 : 200);
    }

    /** Remove a record that has not been invoiced yet (e.g. a typo). */
    public function destroyRecord(Service $service, int $record)
    {
        $row = UsageRecord::where('service_id', $service->id)->findOrFail($record);
        if ($row->isInvoiced()) {
            throw new \DomainException('That usage has already been invoiced and cannot be deleted.');
        }
        $row->delete();
        $this->audit('usage.deleted', $service, 'Deleted a usage record');

        return response()->noContent();
    }

    // ---- meter input ----------------------------------------------------------------------------------------------

    private function ownMeter(Product $product, UsageMeter $meter): UsageMeter
    {
        abort_unless($meter->product_id === $product->id, 404);

        return $meter;
    }

    /** @return array<string, array<int, mixed>> */
    private function meterRules(bool $create = true): array
    {
        $s = $create ? 'required' : 'sometimes';

        return [
            'key' => $create ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/'] : ['prohibited'],
            'name' => [$s, 'string', 'max:255'],
            'unit' => ['nullable', 'string', 'max:40'],
            'aggregation' => [$s, Rule::in(array_keys(UsageMeter::AGGREGATIONS))],
            'included_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'pricing_model' => [$s, Rule::in(array_keys(UsageMeter::MODELS))],
            'package_size' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'prices' => ['nullable', 'array'],
            'prices.*.currency' => ['required', 'string', 'size:3', fn ($a, $v, $fail) => isset(Money::currencies()[strtoupper((string) $v)]) || $fail('The selected currency is invalid.')],
            'prices.*.unit_price' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'prices.*.tiers' => ['nullable', 'array', 'max:50'],
            'prices.*.tiers.*.up_to' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'prices.*.tiers.*.unit_price' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'prices.*.tiers.*.flat_fee' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ];
    }

    /**
     * A syncMeters row from validated input, on top of the meter's current row when updating.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    private function row(array $data, ?array $current = null): array
    {
        $row = $current ?? ['key' => $data['key'], 'unit' => 'unit', 'included_quantity' => UsageMath::norm(0), 'package_size' => null, 'prices' => []];
        foreach (['name', 'aggregation', 'pricing_model'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = $data[$field];
            }
        }
        if (array_key_exists('unit', $data)) {
            $row['unit'] = ($data['unit'] ?? '') !== '' ? $data['unit'] : 'unit';
        }
        if (array_key_exists('included_quantity', $data)) {
            $row['included_quantity'] = UsageMath::norm($data['included_quantity'] ?? 0);
        }
        if (array_key_exists('package_size', $data)) {
            $row['package_size'] = $data['package_size'] === null ? null : UsageMath::norm($data['package_size']);
        }
        if (array_key_exists('prices', $data)) {
            $row['prices'] = $this->prices($data['prices'] ?? [], $row['pricing_model']);
        }
        if ($row['pricing_model'] === 'package') {
            if (empty($row['package_size']) || UsageMath::isZero((string) $row['package_size'])) {
                throw ValidationException::withMessages(['package_size' => 'Package pricing needs a package size.']);
            }
        } else {
            $row['package_size'] = null;
        }
        if ($row['pricing_model'] === 'tiered') {
            foreach ($row['prices'] as $currency => $price) {
                if (! $price['tiers']) {
                    throw ValidationException::withMessages(['prices' => "Tiered pricing needs tiers for $currency."]);
                }
            }
        }

        return $row;
    }

    /**
     * @param  array<int, array<string, mixed>>  $list
     * @return array<string, array{unit_price: int, tiers: array<int, array<string, mixed>>}>
     */
    private function prices(array $list, string $model): array
    {
        $prices = [];
        foreach ($list as $i => $p) {
            $currency = strtoupper((string) $p['currency']);
            if (isset($prices[$currency])) {
                throw ValidationException::withMessages(["prices.$i.currency" => 'Each currency can only have one price.']);
            }
            $tiers = $model === 'tiered' ? array_map(fn ($t) => [
                'up_to' => isset($t['up_to']) ? UsageMath::norm($t['up_to']) : null,
                'unit_price' => (int) $t['unit_price'],
                'flat_fee' => (int) ($t['flat_fee'] ?? 0),
            ], $p['tiers'] ?? []) : [];
            if ($model !== 'tiered' && ! isset($p['unit_price'])) {
                throw ValidationException::withMessages(["prices.$i.unit_price" => 'A unit price is required.']);
            }
            $prices[$currency] = ['unit_price' => (int) ($p['unit_price'] ?? 0), 'tiers' => $tiers];
        }

        return $prices;
    }

    /** @return array<int, array<string, mixed>> the product's meters as syncMeters rows */
    private function currentRows(Product $product): array
    {
        $rows = [];
        foreach ($this->usage->meters($product) as $m) {
            $rows[] = [
                'key' => $m->key, 'name' => $m->name, 'unit' => $m->unit, 'aggregation' => $m->aggregation, 'pricing_model' => $m->pricing_model,
                'included_quantity' => UsageMath::norm($m->included_quantity), 'package_size' => $m->package_size !== null ? UsageMath::norm($m->package_size) : null,
                'prices' => $m->prices->mapWithKeys(fn ($p) => [$p->currency => ['unit_price' => (int) $p->unit_price, 'tiers' => $p->tiers ?? []]])->all(),
            ];
        }

        return $rows;
    }
}
