<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\PaymentService;
use App\Services\TaxService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends ApiController
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'client_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
        ]);
        $query = Invoice::latest('id')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->whereContains('number', $term)->orWhereContains('reference', $term)
                ->orWhereHas('client', fn ($c) => $c->whereContains('name', $term)->orWhereContains('company_name', $term)->orWhereContains('email', $term))));

        return $this->paginate($query, InvoiceResource::class, $request);
    }

    /** What is owed, per currency: open invoices (outstanding) and the overdue part of them. */
    public function summary()
    {
        $due = fn ($q) => $q->external()->selectRaw('currency, count(*) as invoices, sum(total - amount_paid) as due')->groupBy('currency')->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'invoices' => (int) $r->invoices, 'amount' => ['amount' => (int) $r->due, 'currency' => $r->currency, 'formatted' => Money::format((int) $r->due, $r->currency)]])->values();

        return response()->json(['data' => [
            'outstanding' => $due(Invoice::where('status', InvoiceStatus::Open->value)),
            'overdue' => $due(Invoice::overdue()),
        ]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $invoice = $this->invoices->create(Client::findOrFail($data['client_id']), $data['items'], collect($data)->except(['client_id', 'items'])->all());
        $this->audit('invoice.created', $invoice, 'Created invoice '.$invoice->displayNumber());

        return (new InvoiceResource($invoice->load('items', 'payments')))->response()->setStatusCode(201);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('items', 'payments', 'client'));
    }

    /** Edit a draft. Changing client_id re-applies the new client's tax rate. */
    public function update(Request $request, Invoice $invoice): InvoiceResource
    {
        if (! $this->invoices->isEditable($invoice)) {
            throw new \DomainException('Only draft invoices can be edited.');
        }
        $data = $request->validate($this->rules(false));
        $attrs = collect($data)->only(['issue_date', 'due_date', 'notes', 'terms', 'reference'])->all();
        if (isset($data['currency'])) {
            $attrs['currency'] = strtoupper($data['currency']);
        }
        if (isset($data['client_id']) && (int) $data['client_id'] !== $invoice->client_id) {
            $rate = app(TaxService::class)->rateFor(Client::findOrFail($data['client_id']));
            $attrs += ['client_id' => (int) $data['client_id'], 'tax_name' => $rate?->name, 'tax_rate' => $rate?->rate ?? 0];
        }
        $invoice->update($attrs);
        if (isset($data['items'])) {
            $this->invoices->setItems($invoice, $data['items']);
        } else {
            $this->invoices->recalculate($invoice);
        }
        $this->audit('invoice.updated', $invoice, 'Updated invoice #'.$invoice->id);

        return new InvoiceResource($invoice->refresh()->load('items', 'payments'));
    }

    public function destroy(Invoice $invoice)
    {
        $this->invoices->deleteDraft($invoice);
        $this->audit('invoice.deleted', null, 'Deleted draft invoice #'.$invoice->id);

        return response()->noContent();
    }

    public function issue(Request $request, Invoice $invoice): InvoiceResource
    {
        $data = $request->validate(['send' => ['nullable', 'boolean']]);
        $invoice = $this->invoices->issue($invoice, $data['send'] ?? true);
        $this->audit('invoice.issued', $invoice, 'Issued invoice '.$invoice->displayNumber());

        return new InvoiceResource($invoice->load('items', 'payments'));
    }

    /** Email the invoice to the client again. */
    public function resend(Invoice $invoice, Notifier $notifier): InvoiceResource
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            throw new \DomainException('Only open invoices can be re-sent.');
        }
        $notifier->invoiceIssued($invoice);
        $invoice->update(['sent_at' => now()]);
        $this->audit('invoice.resent', $invoice, 'Re-sent invoice '.$invoice->displayNumber());

        return new InvoiceResource($invoice->refresh()->load('items', 'payments'));
    }

    public function void(Invoice $invoice): InvoiceResource
    {
        $invoice = $this->invoices->void($invoice);
        $this->audit('invoice.voided', $invoice, 'Voided invoice '.$invoice->displayNumber());

        return new InvoiceResource($invoice->load('items', 'payments'));
    }

    public function uncollectible(Invoice $invoice): InvoiceResource
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            throw new \DomainException('Only open invoices can be marked uncollectible.');
        }
        $invoice = $this->invoices->markUncollectible($invoice);
        $this->audit('invoice.uncollectible', $invoice, 'Marked invoice '.$invoice->displayNumber().' uncollectible');

        return new InvoiceResource($invoice->load('items', 'payments'));
    }

    /** Record a manual payment (bank transfer, cash, cheque). */
    public function recordPayment(Request $request, Invoice $invoice, PaymentService $payments)
    {
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1'], 'method' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500']]);
        if (! in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::Uncollectible], true)) {
            throw new \DomainException('This invoice cannot accept payments.');
        }
        if ($data['amount'] > $invoice->balance()) {
            throw new \DomainException('The payment is more than the balance due ('.Money::format($invoice->balance(), $invoice->currency).').');
        }
        $payment = $payments->recordManual($invoice, $data['amount'], $data['method'] ?? 'manual', $data['notes'] ?? null);
        $this->audit('payment.recorded', $payment, 'Recorded '.Money::format($data['amount'], $invoice->currency).' against '.$invoice->displayNumber());

        return (new PaymentResource($payment))->response()->setStatusCode(201);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $creating): array
    {
        return [
            'client_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:clients,id'],
            'items' => [$creating ? 'required' : 'sometimes', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.unit_price' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.taxable' => ['nullable', 'boolean'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'currency' => ['nullable', 'string', 'size:3'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'reference' => ['nullable', 'string', 'max:255'],
            'issue' => [$creating ? 'nullable' : 'prohibited', 'boolean'],
            'send' => [$creating ? 'nullable' : 'prohibited', 'boolean'],
        ];
    }
}
