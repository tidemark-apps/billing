<?php

namespace App\Services;

use App\Contracts\ReportsUsage;
use App\Enums\ServiceStatus;
use App\Events\UsageRecorded;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\UsageMeter;
use App\Models\UsageRecord;
use App\Support\Settings;
use App\Support\UsageCharge;
use App\Support\UsageMath;
use App\Support\UsageSummary;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Metered (usage-based) billing: recording usage, pricing it, and handing invoice lines to the BillingRunner.
 *
 * Money precision: see {@see UsageMath}. Prices are integers in micro-minor units (1/1,000,000 of a cent); a line's cost
 * is computed exactly and rounded once to the invoice's minor unit. Quantities are decimal(20,6) strings, never floats.
 *
 * Billing is in arrears: when a service's period closes, every unbilled record recorded before the cut-off is billed on the
 * renewal invoice and stamped (invoiced_at / invoice_item_id), so a second run finds nothing to bill. Records recorded after
 * the cut-off stay unbilled and land on the next invoice.
 */
class UsageService
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    // ---- recording ----------------------------------------------------------------------------------------------

    /**
     * Record usage for a service. Idempotent per (service, meter, idempotency key): repeating a call with the same key returns
     * the original record and fires no event. Quantity is a decimal string or number >= 0.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(Service $service, string $meterKey, float|int|string $quantity, ?CarbonInterface $at = null, ?string $idempotencyKey = null, string $source = 'api', array $metadata = []): UsageRecord
    {
        $meter = $this->meter($service, $meterKey);
        if (! $meter) {
            throw new \InvalidArgumentException("This service's product has no meter \"{$meterKey}\".");
        }
        if (! $service->status->isLive()) {
            throw new \DomainException('Usage can only be recorded for active or suspended services.');
        }
        $qty = UsageMath::norm($quantity);
        if (UsageMath::cmp($qty, '0') < 0) {
            throw new \InvalidArgumentException('Usage quantity cannot be negative.');
        }
        if (UsageMath::cmp($qty, '99999999999999') > 0) {
            throw new \InvalidArgumentException('Usage quantity is too large.');
        }
        $idempotencyKey = $idempotencyKey !== null && $idempotencyKey !== '' ? mb_substr($idempotencyKey, 0, 191) : null;

        if ($idempotencyKey !== null && ($existing = $this->existing($service, $meter, $idempotencyKey))) {
            return $existing;
        }
        try {
            $record = UsageRecord::create([
                'service_id' => $service->id,
                'meter_id' => $meter->id,
                'quantity' => $qty,
                'recorded_at' => $at ?? now(),
                'idempotency_key' => $idempotencyKey,
                'source' => $source,
                'metadata' => $metadata ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($service, $meter, (string) $idempotencyKey) ?? throw new \RuntimeException('Could not record usage.');
        }
        event(new UsageRecorded($record, ['meter' => $meter->key, 'service' => $service->uuid, 'quantity' => $qty]));

        return $record;
    }

    private function existing(Service $service, UsageMeter $meter, string $key): ?UsageRecord
    {
        return UsageRecord::where('service_id', $service->id)->where('meter_id', $meter->id)->where('idempotency_key', $key)->first();
    }

    // ---- meters -------------------------------------------------------------------------------------------------

    /** @return Collection<int, UsageMeter> the product's meters with prices loaded */
    public function meters(Service|Product|null $of): Collection
    {
        $product = $of instanceof Service ? ($of->product ?? $of->load('product')->product) : $of;

        return $product ? UsageMeter::with('prices')->where('product_id', $product->id)->orderBy('sort')->orderBy('id')->get() : collect();
    }

    public function hasMeters(Service|Product $of): bool
    {
        $product = $of instanceof Service ? $of->product : $of;

        return $product !== null && UsageMeter::where('product_id', $product->id)->exists();
    }

    public function meter(Service $service, string $key): ?UsageMeter
    {
        return $this->meters($service)->firstWhere('key', $key);
    }

    /**
     * Replace a product's meters from normalised rows (see App\Support\MeterInput). Existing meters are matched by key so their
     * records survive edits; meters missing from $rows are deleted together with their records (only if none were invoiced).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncMeters(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows) {
            $keep = [];
            foreach ($rows as $i => $row) {
                $meter = UsageMeter::updateOrCreate(['product_id' => $product->id, 'key' => $row['key']], [
                    'name' => $row['name'], 'unit' => $row['unit'], 'aggregation' => $row['aggregation'],
                    'included_quantity' => $row['included_quantity'], 'pricing_model' => $row['pricing_model'],
                    'package_size' => $row['package_size'], 'sort' => $i,
                ]);
                $keep[] = $meter->id;
                $meter->prices()->whereNotIn('currency', array_keys($row['prices']))->delete();
                foreach ($row['prices'] as $currency => $price) {
                    $meter->prices()->updateOrCreate(['currency' => $currency], ['unit_price' => $price['unit_price'], 'tiers' => $price['tiers'] ?: null]);
                }
            }
            $gone = UsageMeter::where('product_id', $product->id)->whereNotIn('id', $keep);
            if (UsageRecord::whereIn('meter_id', (clone $gone)->pluck('id'))->whereNotNull('invoiced_at')->exists()) {
                throw new \DomainException('A meter that has already been invoiced cannot be removed.');
            }
            $gone->delete();
        });
    }

    // ---- pricing ------------------------------------------------------------------------------------------------

    /** Price an already-aggregated quantity: allowance first, then per-unit / graduated tiers / packages. */
    public function cost(UsageMeter $meter, float|int|string $quantity, ?string $currency = null, int $records = 0): UsageCharge
    {
        $currency = strtoupper($currency ?: (string) Settings::get('billing.currency', config('billing.currency')));
        $qty = UsageMath::norm($quantity);
        $included = UsageMath::norm($meter->included_quantity);
        $billable = UsageMath::billable($qty, $included);
        $price = $meter->priceFor($currency);
        // A fallback price in the store currency is used as-is (the same convention as product prices).

        $micro = '0';
        if ($price && ! UsageMath::isZero($billable)) {
            $micro = match ($meter->pricing_model) {
                'tiered' => UsageMath::tieredMicro($billable, $price->tiers ?? []),
                'package' => UsageMath::packageMicro($billable, UsageMath::norm($meter->package_size), $price->unit_price),
                default => UsageMath::lineMicro($billable, $price->unit_price),
            };
        }

        return new UsageCharge($meter, $qty, $included, $billable, $micro, UsageMath::toMinor($micro), $currency, $price !== null, $records);
    }

    /** Aggregate unbilled records of a meter into one quantity. @return array{quantity: string, records: int, max_id: int}|null null when nothing to bill */
    public function aggregate(Service $service, UsageMeter $meter, ?CarbonInterface $cutoff = null): ?array
    {
        $q = UsageRecord::where('service_id', $service->id)->where('meter_id', $meter->id)->whereNull('invoiced_at');
        if ($cutoff) {
            $q->where('recorded_at', '<', $cutoff);
        }
        $stats = (clone $q)->selectRaw('count(*) as n, max(id) as max_id')->first();
        if (! $stats || (int) $stats->n === 0) {
            return null;
        }
        $q->where('id', '<=', $stats->max_id); // a snapshot: records arriving mid-calculation wait for the next invoice

        $quantity = match ($meter->aggregation) {
            'max' => UsageMath::norm((clone $q)->max('quantity')),
            'last' => UsageMath::norm((clone $q)->orderByDesc('recorded_at')->orderByDesc('id')->value('quantity')),
            default => UsageMath::norm((clone $q)->sum('quantity')),
        };

        return ['quantity' => $quantity, 'records' => (int) $stats->n, 'max_id' => (int) $stats->max_id];
    }

    // ---- summaries ----------------------------------------------------------------------------------------------

    /** The period that ends at the service's next due date: [start, end). */
    public function period(Service $service): array
    {
        $end = $service->next_due_date?->copy()->startOfDay();
        $months = $service->billing_cycle->months();
        $start = $end && $months > 0 ? $end->copy()->subMonthsNoOverflow($months) : ($service->activated_at?->copy()->startOfDay() ?? $service->created_at?->copy()->startOfDay());

        return [$start, $end];
    }

    /** Unbilled usage for the current period with its estimated charge. Only meters that have records appear. */
    public function currentPeriodSummary(Service $service): UsageSummary
    {
        [$start, $end] = $this->period($service);

        return new UsageSummary($start, $end, $service->currency, $this->charges($service));
    }

    /** @return Collection<int, UsageCharge> the unbilled charge for every meter that has usage before the cut-off (default: everything) */
    public function charges(Service $service, ?CarbonInterface $cutoff = null): Collection
    {
        $lines = collect();
        foreach ($this->meters($service) as $meter) {
            if ($agg = $this->aggregate($service, $meter, $cutoff)) {
                $lines->push($this->cost($meter, $agg['quantity'], $service->currency, $agg['records']));
            }
        }

        return $lines;
    }

    /** @return Collection<int, UsageRecord> */
    public function recentRecords(Service $service, int $limit = 15): Collection
    {
        return UsageRecord::with('meter')->where('service_id', $service->id)->orderByDesc('recorded_at')->orderByDesc('id')->limit($limit)->get();
    }

    // ---- invoicing ----------------------------------------------------------------------------------------------

    /**
     * Build invoice items for everything unbilled before $cutoff. Nothing is marked yet: call settle() once the invoice exists.
     * Zero-cost lines are dropped (their records are still marked billed) unless setting billing.usage_zero_lines is on.
     *
     * @return array{items: array<int, array<string, mixed>>, claims: array<int, array<string, mixed>>}
     */
    public function collect(Service $service, CarbonInterface $cutoff, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null): array
    {
        $includeZero = filter_var(Settings::get('billing.usage_zero_lines', false), FILTER_VALIDATE_BOOLEAN);
        $service->loadMissing('product', 'client');
        $internal = InternalAccounts::is($service->client); // internal accounts are not charged for usage; the records are still marked billed
        $items = [];
        $claims = [];
        $label = $periodStart && $periodEnd ? ' — '.$periodStart->format('j M Y').' to '.$periodEnd->format('j M Y') : '';
        foreach ($this->meters($service) as $meter) {
            $agg = $this->aggregate($service, $meter, $cutoff);
            if (! $agg) {
                continue;
            }
            $charge = $this->cost($meter, $agg['quantity'], $service->currency, $agg['records']);
            $claim = ['meter_id' => $meter->id, 'max_id' => $agg['max_id'], 'cutoff' => $cutoff->copy(), 'item' => null];
            if ((! $charge->isZero() && ! $internal) || $includeZero) {
                $claim['item'] = count($items);
                $items[] = [
                    'kind' => 'usage',
                    'description' => $service->title().' · '.$meter->name.$label.' ('.$charge->describe().')',
                    'quantity' => 1,
                    'unit_price' => $internal ? 0 : $charge->amount,
                    'taxable' => ! ($service->product?->tax_exempt ?? false),
                    'service_id' => $service->id,
                    'product_id' => $service->product_id,
                    'period_start' => $periodStart?->toDateString(),
                    'period_end' => $periodEnd?->toDateString(),
                ];
            }
            $claims[] = $claim;
        }

        return ['items' => $items, 'claims' => $claims];
    }

    /**
     * Mark the claimed records as billed. $itemMap translates a claim's item index to the created InvoiceItem (null for dropped zero lines).
     *
     * @param  array<int, array<string, mixed>>  $claims
     * @param  array<int, InvoiceItem>  $itemMap
     */
    public function settle(Service $service, array $claims, array $itemMap): int
    {
        $marked = 0;
        foreach ($claims as $claim) {
            $marked += UsageRecord::where('service_id', $service->id)->where('meter_id', $claim['meter_id'])
                ->whereNull('invoiced_at')->where('id', '<=', $claim['max_id'])->where('recorded_at', '<', $claim['cutoff'])
                ->update(['invoiced_at' => now(), 'invoice_item_id' => $claim['item'] !== null ? ($itemMap[$claim['item']]->id ?? null) : null]);
        }

        return $marked;
    }

    // ---- pulling from provisioning modules ------------------------------------------------------------------------

    /** Ask every module that implements ReportsUsage for yesterday-and-earlier usage. @return int records stored */
    public function pullAll(): int
    {
        $stored = 0;
        Service::with('product')->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value])
            ->whereHas('product.meters')->each(function (Service $service) use (&$stored) {
                $stored += $this->pull($service);
            });

        return $stored;
    }

    public function pull(Service $service): int
    {
        $module = $this->provisioning->moduleFor($service);
        if (! $module instanceof ReportsUsage || ! $service->status->isLive()) {
            return 0;
        }
        $to = now()->startOfDay();
        $since = $service->config['usage_pulled_until'] ?? null;
        $from = $since ? now()->parse($since) : ($service->activated_at?->copy()->startOfDay() ?? $to->copy()->subDay());
        if ($from->gte($to)) {
            return 0;
        }
        try {
            $reported = $module->pullUsage($service, $from, $to);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
        $stored = 0;
        $keys = $this->meters($service)->pluck('key')->all();
        foreach ($reported as $key => $quantity) {
            if (! in_array((string) $key, $keys, true)) {
                continue;
            }
            try {
                $id = 'pull:'.$from->toDateString().':'.$to->toDateString();
                $fresh = ! $this->existing($service, $this->meter($service, (string) $key), $id);
                $this->record($service, (string) $key, $quantity, $to->copy()->subSecond(), $id, 'pull');
                $stored += $fresh ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $service->update(['config' => array_merge($service->config ?? [], ['usage_pulled_until' => $to->toDateString()])]);

        return $stored;
    }

    /** Price shown to staff / customers, e.g. "0.002 USD". */
    public static function formatMicro(int $micro, string $currency): string
    {
        return UsageMath::microToMajor($micro, $currency, 2).' '.strtoupper($currency);
    }
}
