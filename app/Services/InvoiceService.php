<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Events\InvoiceCreated;
use App\Events\InvoicePaid;
use App\Events\InvoiceVoided;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Sequence;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private readonly TaxService $tax, private readonly Notifier $notifier) {}

    /**
     * Create an invoice. Items are arrays of: description, unit_price (minor units), quantity?, taxable?, kind?, service_id?, product_id?, period_start?, period_end?.
     * Pass 'issue' => true to open it straight away (assigns a number and emails the client).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $attrs  currency, issue_date, due_date, notes, terms, reference, quote_id, issue, send
     */
    public function create(Client $client, array $items, array $attrs = []): Invoice
    {
        $invoice = DB::transaction(function () use ($client, $items, $attrs) {
            $rate = $this->tax->rateFor($client);
            $invoice = Invoice::create([
                'client_id' => $client->id,
                'quote_id' => $attrs['quote_id'] ?? null,
                'status' => InvoiceStatus::Draft,
                'currency' => $attrs['currency'] ?? $client->currency,
                'issue_date' => $attrs['issue_date'] ?? null,
                'due_date' => $attrs['due_date'] ?? null,
                'notes' => $attrs['notes'] ?? null,
                'terms' => $attrs['terms'] ?? Settings::get('billing.terms'),
                'reference' => $attrs['reference'] ?? null,
                'tax_name' => $rate?->name,
                'tax_rate' => $rate?->rate ?? 0,
            ]);
            $this->setItems($invoice, $items);

            return $invoice;
        });

        return ($attrs['issue'] ?? false) ? $this->issue($invoice, $attrs['send'] ?? true) : $invoice->refresh();
    }

    /** Replace all line items and recalculate totals. @param  array<int, array<string, mixed>>  $items */
    public function setItems(Invoice $invoice, array $items): Invoice
    {
        $this->assertEditable($invoice);
        $invoice->items()->delete();
        foreach (array_values($items) as $i => $item) {
            $qty = (float) ($item['quantity'] ?? 1);
            $unit = (int) $item['unit_price'];
            $invoice->items()->create([
                'service_id' => $item['service_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'kind' => $item['kind'] ?? 'custom',
                'description' => $item['description'],
                'quantity' => $qty,
                'unit_price' => $unit,
                'taxable' => $item['taxable'] ?? true,
                'total' => (int) round($qty * $unit),
                'period_start' => $item['period_start'] ?? null,
                'period_end' => $item['period_end'] ?? null,
                'sort' => $i,
            ]);
        }

        return $this->recalculate($invoice);
    }

    public function recalculate(Invoice $invoice): Invoice
    {
        $items = $invoice->items()->get();
        $subtotal = (int) $items->sum('total');
        $taxable = (int) $items->where('taxable', true)->sum('total');
        $tax = (int) round($taxable * ((float) $invoice->tax_rate) / 100);
        $invoice->update(['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => $subtotal + $tax]);

        return $invoice->refresh();
    }

    /** Draft → open: assign a number and dates, email the client. A zero-total invoice is marked paid straight away. */
    public function issue(Invoice $invoice, bool $send = true): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return $invoice;
        }
        if ($invoice->items()->count() === 0) {
            throw new \DomainException('An invoice needs at least one line item before it can be issued.');
        }
        $issue = $invoice->issue_date ?? now();
        $invoice->update([
            'number' => Sequence::format((string) Settings::get('billing.invoice_prefix', 'INV-'), Sequence::next('invoice')),
            'status' => InvoiceStatus::Open,
            'issue_date' => $issue,
            'due_date' => $invoice->due_date ?? $issue->copy()->addDays((int) Settings::get('billing.due_days', 7)),
            'sent_at' => $send ? now() : null,
        ]);
        $invoice->refresh();

        // Email first: InvoiceCreated can trigger autopay, and the "new invoice" email must not arrive after the receipt.
        if ($send && $invoice->total !== 0) {
            $this->notifier->invoiceIssued($invoice);
        }
        event(new InvoiceCreated($invoice));
        if ($invoice->total === 0) {
            return $this->markPaid($invoice);
        }

        return $invoice->refresh();
    }

    /** Called by PaymentService once the balance is cleared (or for free invoices). */
    public function markPaid(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Paid) {
            return $invoice;
        }
        $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => now()]);
        event(new InvoicePaid($invoice->refresh()));

        return $invoice;
    }

    public function void(Invoice $invoice): Invoice
    {
        if (! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Open, InvoiceStatus::Uncollectible], true)) {
            throw new \DomainException('Only unpaid invoices can be voided. Refund the payment first.');
        }
        if ($invoice->amount_paid > 0) {
            throw new \DomainException('This invoice has payments recorded. Refund them before voiding it.');
        }
        $invoice->update(['status' => InvoiceStatus::Void]);
        event(new InvoiceVoided($invoice->refresh()));

        return $invoice;
    }

    public function markUncollectible(Invoice $invoice): Invoice
    {
        $invoice->update(['status' => InvoiceStatus::Uncollectible]);

        return $invoice->refresh();
    }

    public function deleteDraft(Invoice $invoice): void
    {
        $this->assertEditable($invoice);
        $invoice->delete();
    }

    public function isEditable(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft;
    }

    private function assertEditable(Invoice $invoice): void
    {
        if (! $this->isEditable($invoice)) {
            throw new \DomainException('Only draft invoices can be edited.');
        }
    }
}
