<?php

namespace App\Support;

use App\Models\Product;
use App\Models\UsageMeter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Translates the "Metered usage" section of the admin product form to and from App\Services\UsageService::syncMeters() rows. */
class MeterInput
{
    /**
     * Normalised meter rows from the request, or null when the form did not include the section (so API-style posts leave meters alone).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function fromRequest(Request $request): ?array
    {
        if (! $request->has('meters_present')) {
            return null;
        }
        $currencies = array_keys(Money::currencies());
        $data = Validator::make($request->all(), [
            'meters' => ['nullable', 'array', 'max:50'],
            'meters.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/'],
            'meters.*.name' => ['required', 'string', 'max:255'],
            'meters.*.unit' => ['nullable', 'string', 'max:40'],
            'meters.*.aggregation' => ['required', Rule::in(array_keys(UsageMeter::AGGREGATIONS))],
            'meters.*.included_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'meters.*.pricing_model' => ['required', Rule::in(array_keys(UsageMeter::MODELS))],
            'meters.*.package_size' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'meters.*.prices' => ['nullable', 'array'],
            'meters.*.prices.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'meters.*.prices.*.tiers' => ['nullable', 'string', 'max:5000'],
        ], ['meters.*.key.regex' => 'Meter keys use lowercase letters, numbers, dashes and underscores.'])->validate();

        $rows = [];
        $seen = [];
        foreach (array_values($data['meters'] ?? []) as $i => $m) {
            if (isset($seen[$m['key']])) {
                throw ValidationException::withMessages(["meters.$i.key" => 'Each meter needs a unique key.']);
            }
            $seen[$m['key']] = true;
            $model = $m['pricing_model'];
            if ($model === 'package' && empty($m['package_size'])) {
                throw ValidationException::withMessages(["meters.$i.package_size" => 'Package pricing needs a package size.']);
            }
            $prices = [];
            foreach ($m['prices'] ?? [] as $currency => $p) {
                $currency = strtoupper((string) $currency);
                if (! in_array($currency, $currencies, true)) {
                    continue;
                }
                $tiers = $model === 'tiered' ? self::parseTiers((string) ($p['tiers'] ?? ''), $currency, "meters.$i.prices.$currency.tiers") : [];
                $unit = UsageMath::majorToMicro($p['unit_price'] ?? 0, $currency);
                if (($p['unit_price'] ?? '') === '' && ! $tiers) {
                    continue; // no price for this currency
                }
                $prices[$currency] = ['unit_price' => $unit, 'tiers' => $tiers];
            }
            $rows[] = [
                'key' => $m['key'], 'name' => $m['name'], 'unit' => ($m['unit'] ?? '') !== '' ? $m['unit'] : 'unit',
                'aggregation' => $m['aggregation'], 'pricing_model' => $model,
                'included_quantity' => UsageMath::norm($m['included_quantity'] ?? 0),
                'package_size' => $model === 'package' ? UsageMath::norm($m['package_size']) : null,
                'prices' => $prices,
            ];
        }

        return $rows;
    }

    /** One tier per line: "up to | price per unit | flat fee (optional)". Leave "up to" blank (or "inf") on the last tier. */
    private static function parseTiers(string $text, string $currency, string $field): array
    {
        $tiers = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = array_map('trim', explode('|', $line));
            $upTo = $cols[0] ?? '';
            $unbounded = $upTo === '' || in_array(strtolower($upTo), ['inf', 'infinity', '∞', '*'], true);
            foreach ([$unbounded ? null : $upTo, $cols[1] ?? '0', $cols[2] ?? '0'] as $n) {
                if ($n !== null && ! preg_match('/^\d+(\.\d+)?$/', $n)) {
                    throw ValidationException::withMessages([$field => 'Tiers are written as "up to | price | flat fee", one per line, with plain numbers.']);
                }
            }
            $tiers[] = ['up_to' => $unbounded ? null : UsageMath::norm($upTo), 'unit_price' => UsageMath::majorToMicro($cols[1] ?? 0, $currency), 'flat_fee' => UsageMath::majorToMicro($cols[2] ?? 0, $currency)];
        }

        return $tiers;
    }

    /**
     * Rows for the form (old input wins), each with a price entry for every offered currency.
     *
     * @param  array<int, string>  $currencies
     * @return array<int, array<string, mixed>>
     */
    public static function formRows(Product $product, array $currencies): array
    {
        if (is_array(old('meters'))) {
            return array_values(old('meters'));
        }
        $rows = [];
        foreach ($product->exists ? $product->meters()->with('prices')->get() : [] as $meter) {
            $prices = [];
            foreach ($meter->prices as $p) {
                $prices[$p->currency] = ['unit_price' => $p->unit_price ? UsageMath::microToMajor($p->unit_price, $p->currency, 2) : '', 'tiers' => self::tiersToText($p->tiers ?? [], $p->currency)];
            }
            $rows[] = [
                'key' => $meter->key, 'name' => $meter->name, 'unit' => $meter->unit, 'aggregation' => $meter->aggregation, 'pricing_model' => $meter->pricing_model,
                'included_quantity' => UsageMath::plain((string) $meter->included_quantity) === '0' ? '' : UsageMath::plain((string) $meter->included_quantity),
                'package_size' => $meter->package_size !== null ? UsageMath::plain((string) $meter->package_size) : '',
                'prices' => $prices,
            ];
        }

        return $rows;
    }

    /** @param  array<int, array<string, mixed>>  $tiers */
    private static function tiersToText(array $tiers, string $currency): string
    {
        return collect(UsageMath::sortTiers($tiers))->map(function ($t) use ($currency) {
            $flat = (int) ($t['flat_fee'] ?? 0);

            return trim(($t['up_to'] === null ? '' : UsageMath::plain((string) $t['up_to'])).' | '.UsageMath::microToMajor((int) $t['unit_price'], $currency, 2).($flat ? ' | '.UsageMath::microToMajor($flat, $currency, 2) : ''));
        })->implode("\n");
    }
}
