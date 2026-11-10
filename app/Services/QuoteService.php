<?php

namespace App\Services;

use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Events\QuoteAccepted;
use App\Events\QuoteDeclined;
use App\Events\QuoteSent;
use App\Events\ServiceCreated;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Service;
use App\Support\Sequence;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class QuoteService
{
    public function __construct(private readonly TaxService $tax, private readonly InvoiceService $invoices, private readonly Notifier $notifier) {}

    /**
     * Items: description, unit_price, quantity?, taxable?, product_id?, billing_cycle? (a product + cycle makes accepting it order the service).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $attrs  title, currency, valid_until, notes, terms, send
     */
    public function create(Client $client, array $items, array $attrs = []): Quote
    {
        $rate = $this->tax->rateFor($client);
        $quote = Quote::create([
            'client_id' => $client->id,
            'status' => QuoteStatus::Draft,
            'currency' => $attrs['currency'] ?? $client->currency,
            'title' => $attrs['title'] ?? null,
            'valid_until' => $attrs['valid_until'] ?? now()->addDays((int) Settings::get('billing.quote_valid_days', 30)),
            'notes' => $attrs['notes'] ?? null,
            'terms' => $attrs['terms'] ?? Settings::get('billing.terms'),
            'tax_name' => $rate?->name,
            'tax_rate' => $rate?->rate ?? 0,
        ]);
        $this->setItems($quote, $items);

        return ($attrs['send'] ?? false) ? $this->send($quote) : $quote->refresh();
    }

    /** @param  array<int, array<string, mixed>>  $items */
    public function setItems(Quote $quote, array $items): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new \DomainException('Only draft quotes can be edited.');
        }
        $quote->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unit = (int) $item['unit_price'];
            $quote->items()->create([
                'product_id' => $item['product_id'] ?? null,
                'billing_cycle' => $item['billing_cycle'] ?? null,
                'label' => $item['label'] ?? null,
                'description' => $item['description'],
                'quantity' => $qty,
                'unit_price' => $unit,
                'taxable' => $item['taxable'] ?? true,
                'total' => (int) round($qty * $unit),
                'sort' => $i,
            ]);
        }

        return $this->recalculate($quote);
    }

    public function recalculate(Quote $quote): Quote
    {
        $items = $quote->items()->get();
        $subtotal = (int) $items->sum('total');
        $tax = (int) round((int) $items->where('taxable', true)->sum('total') * ((float) $quote->tax_rate) / 100);
        $quote->update(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $subtotal + $tax]);

        return $quote->refresh();
    }

    public function send(Quote $quote): Quote
    {
        if (! in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Sent], true)) {
            throw new \DomainException('This quote can no longer be sent.');
        }
        if ($quote->items()->count() === 0) {
            throw new \DomainException('A quote needs at least one line item.');
        }
        $quote->update([
            'number' => $quote->number ?? Sequence::format((string) Settings::get('billing.quote_prefix', 'QUO-'), Sequence::next('quote')),
            'status' => QuoteStatus::Sent,
            'issue_date' => $quote->issue_date ?? now(),
            'sent_at' => now(),
        ]);
        $quote->refresh();
        event(new QuoteSent($quote));
        $this->notifier->quoteSent($quote);

        return $quote;
    }

    public function accept(Quote $quote, ?string $acceptedBy = null): Quote
    {
        if (! $quote->isOpenForResponse()) {
            throw new \DomainException('This quote can no longer be accepted.');
        }
        $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now(), 'accepted_by' => $acceptedBy]);
        event(new QuoteAccepted($quote->refresh()));
        $this->notifier->quoteAccepted($quote);

        if (Settings::bool('billing.quote_auto_invoice', true)) {
            $this->convert($quote);
        }

        return $quote->refresh();
    }

    public function decline(Quote $quote): Quote
    {
        if (! $quote->isOpenForResponse()) {
            throw new \DomainException('This quote can no longer be declined.');
        }
        $quote->update(['status' => QuoteStatus::Declined, 'declined_at' => now()]);
        event(new QuoteDeclined($quote->refresh()));

        return $quote;
    }

    /** Turn an accepted quote into an open invoice, creating pending services for product lines. */
    public function convert(Quote $quote): Invoice
    {
        if ($quote->invoice_id) {
            return $quote->invoice;
        }
        if (! in_array($quote->status, [QuoteStatus::Accepted, QuoteStatus::Sent, QuoteStatus::Draft], true)) {
            throw new \DomainException('This quote cannot be converted.');
        }

        return DB::transaction(function () use ($quote) {
            $items = [];
            $created = [];
            foreach ($quote->items as $item) {
                $row = ['description' => $item->description, 'unit_price' => $item->unit_price, 'quantity' => $item->quantity, 'taxable' => $item->taxable, 'product_id' => $item->product_id, 'kind' => 'custom'];
                if ($item->product_id && $item->billing_cycle) {
                    $service = Service::create([
                        'client_id' => $quote->client_id,
                        'product_id' => $item->product_id,
                        'status' => ServiceStatus::Pending,
                        'label' => $item->label,
                        'billing_cycle' => $item->billing_cycle,
                        'currency' => $quote->currency,
                        ...InternalAccounts::pricing($quote->client, (int) $item->unit_price),
                    ]);
                    $created[] = $service;
                    $row['kind'] = 'product';
                    $row['unit_price'] = $service->amount;
                    $row['service_id'] = $service->id;
                }
                $items[] = $row;
            }
            $invoice = $this->invoices->create($quote->client, $items, [
                'currency' => $quote->currency,
                'quote_id' => $quote->id,
                'notes' => $quote->notes,
                'terms' => $quote->terms,
                'reference' => $quote->number,
                'issue' => true,
            ]);
            $quote->update(['status' => QuoteStatus::Converted, 'invoice_id' => $invoice->id]);
            foreach ($created as $service) {
                event(new ServiceCreated($service));
            }

            return $invoice;
        });
    }

    public function expireOverdue(): int
    {
        return Quote::where('status', QuoteStatus::Sent->value)->whereDate('valid_until', '<', now()->toDateString())
            ->update(['status' => QuoteStatus::Expired->value]);
    }
}
