<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Validation\Rule;

/** Shared by the invoice and quote forms: validating, converting and pre-filling line items. */
trait HandlesLineItems
{
    /** @return array<string, mixed> */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.taxable' => ['nullable', 'boolean'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'items.*.label' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** Fix-it messages shown inline next to the offending field. @return array<string, string> */
    protected function formMessages(): array
    {
        return [
            'client_id.required' => 'Choose a client.',
            'client_id.exists' => 'That client no longer exists. Choose another.',
            'currency.required' => 'Choose a currency.',
            'issue_date.date' => 'Enter a valid date, for example 2026-03-31.',
            'due_date.date' => 'Enter a valid date, for example 2026-03-31.',
            'valid_until.date' => 'Enter a valid date, for example 2026-03-31.',
            'items.required' => 'Add at least one line item.',
            'items.min' => 'Add at least one line item.',
            'items.*.description.required' => 'Describe this line.',
            'items.*.description.max' => 'Keep the description under 255 characters.',
            'items.*.unit_price.required' => 'Enter a unit price (0 is allowed).',
            'items.*.unit_price.numeric' => 'Enter the price as a number, such as 12.50.',
            'items.*.unit_price.min' => 'The price cannot be negative.',
            'items.*.quantity.numeric' => 'Enter the quantity as a number.',
            'items.*.quantity.min' => 'The quantity cannot be negative.',
        ];
    }

    /**
     * Form input (major units) to service input (minor units).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function parseItems(array $items, string $currency, bool $withCycle = false): array
    {
        return collect($items)->values()->map(function (array $i) use ($currency, $withCycle) {
            $row = [
                'description' => $i['description'],
                'quantity' => isset($i['quantity']) && $i['quantity'] !== '' ? (float) $i['quantity'] : 1,
                'unit_price' => Money::toMinor($i['unit_price'], $currency),
                'taxable' => filter_var($i['taxable'] ?? true, FILTER_VALIDATE_BOOL),
                'product_id' => ! empty($i['product_id']) ? (int) $i['product_id'] : null,
            ];
            if ($withCycle) {
                $row['billing_cycle'] = ! empty($i['product_id']) && ! empty($i['billing_cycle']) ? $i['billing_cycle'] : null;
                $row['label'] = $i['label'] ?? null;
            }

            return $row;
        })->all();
    }

    /** Rows to render: old input after a failed submit, else the model's items, else one blank row. @return array<int, array<string, mixed>> */
    protected function rowsForForm(?iterable $items, string $currency): array
    {
        $old = old('items');
        if (is_array($old)) {
            return array_values($old);
        }
        $rows = [];
        foreach ($items ?? [] as $i) {
            $rows[] = [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => Money::toDecimal((int) $i->unit_price, $currency),
                'taxable' => $i->taxable,
                'product_id' => $i->product_id,
                'billing_cycle' => $i->billing_cycle?->value ?? null,
                'label' => $i->label ?? null,
            ];
        }

        return $rows ?: [['quantity' => 1, 'taxable' => true]];
    }

    /** Product + cycle choices for the "add from catalogue" picker. @return array<int, array<string, mixed>> */
    protected function catalog(): array
    {
        $out = [];
        Product::with('prices')->where('is_active', true)->orderBy('name')->get()->each(function (Product $p) use (&$out) {
            foreach ($p->prices->sortBy(fn ($pr) => $pr->cycle->months()) as $pr) {
                $out[] = [
                    'product_id' => $p->id,
                    'cycle' => $pr->cycle->value,
                    'currency' => $pr->currency,
                    'amount' => Money::toDecimal($pr->amount, $pr->currency),
                    'label' => $p->name.' · '.$pr->cycle->label().' · '.$pr->currency,
                    'description' => $p->name.($pr->cycle->isRecurring() ? ' ('.$pr->cycle->label().')' : ''),
                ];
            }
        });

        return $out;
    }

    /** @return array<string, int> currency => decimal digits, for the live totals script */
    protected function digitsMap(): array
    {
        return collect(Money::currencies())->mapWithKeys(fn ($l, $c) => [$c => Money::digits($c)])->all();
    }
}
