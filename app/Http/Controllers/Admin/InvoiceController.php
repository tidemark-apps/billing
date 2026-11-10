<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Admin\Concerns\HandlesLineItems;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\Notifier;
use App\Services\PaymentService;
use App\Services\TaxService;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InvoiceController extends Controller
{
    use HandlesLineItems;

    public function __construct(private readonly InvoiceService $invoices, private readonly PaymentService $payments) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InvoiceStatus::class)],
            'client_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'overdue' => ['nullable', 'boolean'],
            'view' => ['nullable', Rule::in(['open', 'overdue', 'paid', 'draft', 'closed'])],
        ]);
        $view = $filters['view'] ?? 'all';
        unset($filters['view']);

        $query = Invoice::with('client')
            ->when($view === 'open', fn ($q) => $q->where('status', InvoiceStatus::Open->value))
            ->when($view === 'overdue', fn ($q) => $q->overdue())
            ->when($view === 'paid', fn ($q) => $q->where('status', InvoiceStatus::Paid->value))
            ->when($view === 'draft', fn ($q) => $q->where('status', InvoiceStatus::Draft->value))
            ->when($view === 'closed', fn ($q) => $q->whereIn('status', [InvoiceStatus::Void->value, InvoiceStatus::Uncollectible->value]))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('issue_date', '<=', $v))
            ->when(! empty($filters['overdue']), fn ($q) => $q->overdue())
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->whereContains('number', $term)->orWhereContains('reference', $term)
                    ->orWhereHas('client', fn ($c) => $c->whereContains('name', $term)->orWhereContains('company_name', $term)->orWhereContains('email', $term)));
            });

        $due = fn ($q) => $q->external()->selectRaw('currency, sum(total - amount_paid) as due')->groupBy('currency')->pluck('due', 'currency');

        return view('admin.invoices.index', [
            'invoices' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'view' => $view,
            'counts' => [
                'all' => Invoice::count(),
                'open' => Invoice::where('status', InvoiceStatus::Open->value)->count(),
                'overdue' => Invoice::overdue()->count(),
                'paid' => Invoice::where('status', InvoiceStatus::Paid->value)->count(),
                'draft' => Invoice::where('status', InvoiceStatus::Draft->value)->count(),
                'closed' => Invoice::whereIn('status', [InvoiceStatus::Void->value, InvoiceStatus::Uncollectible->value])->count(),
            ],
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company_name']),
            'outstanding' => $due(Invoice::where('status', InvoiceStatus::Open->value)),
            'overdue' => $due(Invoice::overdue()),
        ]);
    }

    public function create(Request $request)
    {
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;

        return view('admin.invoices.form', $this->formData(null, $client));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $client = Client::findOrFail($data['client_id']);
        $currency = strtoupper($data['currency']);

        try {
            $invoice = $this->invoices->create($client, $this->parseItems($data['items'], $currency), [
                'currency' => $currency,
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'issue' => $request->input('action') === 'issue',
            ]);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        Activity::log('invoice.created', $invoice, 'Created invoice '.$invoice->displayNumber());

        return redirect()->route('admin.invoices.show', $invoice)->with('status', $invoice->status === InvoiceStatus::Draft ? 'Draft saved.' : 'Invoice issued and emailed to the client.');
    }

    public function show(Invoice $invoice)
    {
        $invoice->load('client', 'items.service.product', 'payments');

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'services' => $invoice->items->pluck('service')->filter()->unique('id'),
            'refundable' => $invoice->payments->where('type', 'payment')->mapWithKeys(fn ($p) => [$p->id => $p->status->value === 'completed' ? $p->amount - $p->refundedAmount() : 0]),
        ]);
    }

    public function edit(Invoice $invoice)
    {
        if (! $this->invoices->isEditable($invoice)) {
            return redirect()->route('admin.invoices.show', $invoice)->with('error', 'Only draft invoices can be edited.');
        }

        return view('admin.invoices.form', $this->formData($invoice, $invoice->client));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        if (! $this->invoices->isEditable($invoice)) {
            return redirect()->route('admin.invoices.show', $invoice)->with('error', 'Only draft invoices can be edited.');
        }
        $data = $this->validated($request);
        $currency = strtoupper($data['currency']);

        try {
            $attrs = [
                'currency' => $currency,
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ];
            if ((int) $data['client_id'] !== $invoice->client_id) {
                $rate = app(TaxService::class)->rateFor(Client::findOrFail($data['client_id']));
                $attrs += ['client_id' => (int) $data['client_id'], 'tax_name' => $rate?->name, 'tax_rate' => $rate?->rate ?? 0];
            }
            $invoice->update($attrs);
            $this->invoices->setItems($invoice, $this->parseItems($data['items'], $currency));
            if ($request->input('action') === 'issue') {
                $this->invoices->issue($invoice->refresh());
            }
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        Activity::log('invoice.updated', $invoice, 'Updated invoice '.$invoice->displayNumber());

        return redirect()->route('admin.invoices.show', $invoice)->with('status', $invoice->refresh()->status === InvoiceStatus::Draft ? 'Draft saved.' : 'Invoice issued and emailed to the client.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        try {
            $this->invoices->deleteDraft($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('invoice.deleted', null, 'Deleted draft invoice #'.$invoice->id);

        return redirect()->route('admin.invoices.index')->with('status', 'Draft deleted.');
    }

    public function issue(Invoice $invoice): RedirectResponse
    {
        try {
            $this->invoices->issue($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('invoice.issued', $invoice, 'Issued invoice '.$invoice->refresh()->displayNumber());

        return back()->with('status', 'Invoice issued and emailed to the client.');
    }

    public function resend(Invoice $invoice, Notifier $notifier): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            return back()->with('error', 'Only open invoices can be re-sent.');
        }
        $notifier->invoiceIssued($invoice);
        $invoice->update(['sent_at' => now()]);

        return back()->with('status', 'Invoice emailed to '.$invoice->client->email.'.');
    }

    public function void(Invoice $invoice): RedirectResponse
    {
        try {
            $this->invoices->void($invoice);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('invoice.voided', $invoice, 'Voided invoice '.$invoice->displayNumber());

        return back()->with('status', 'Invoice voided.');
    }

    public function uncollectible(Invoice $invoice): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Open) {
            return back()->with('error', 'Only open invoices can be marked uncollectible.');
        }
        $this->invoices->markUncollectible($invoice);
        Activity::log('invoice.uncollectible', $invoice, 'Marked invoice '.$invoice->displayNumber().' uncollectible');

        return back()->with('status', 'Marked as uncollectible.');
    }

    public function recordPayment(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Enter the amount you received.',
            'amount.numeric' => 'Enter the amount as a number, such as 120.00.',
            'amount.gt' => 'The amount must be more than zero.',
            'method.required' => 'Say how it was paid, for example bank transfer.',
        ]);
        $amount = Money::toMinor($data['amount'], $invoice->currency);
        if (! in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::Uncollectible], true)) {
            return back()->with('error', 'This invoice cannot accept payments.');
        }
        if ($amount > $invoice->balance()) {
            return back()->withInput()->withErrors(['amount' => 'That is more than the balance due. Enter '.money($invoice->balance(), $invoice->currency).' or less.']);
        }
        try {
            $payment = $this->payments->recordManual($invoice, $amount, $data['method'], $data['notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('payment.recorded', $payment, 'Recorded '.money($amount, $invoice->currency).' against '.$invoice->displayNumber());

        return back()->with('status', 'Payment recorded.');
    }

    /** Tax rate and currency for a client, used by the form to update totals live. */
    public function taxRate(Client $client, TaxService $tax): JsonResponse
    {
        $rate = $tax->rateFor($client);

        return response()->json(['rate' => $rate?->rate ?? 0, 'name' => $rate?->name, 'currency' => $client->currency]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'currency' => ['required', 'string', 'size:3'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ] + $this->itemRules(), $this->formMessages());
    }

    /** @return array<string, mixed> */
    private function formData(?Invoice $invoice, ?Client $client): array
    {
        $currency = $invoice?->currency ?? $client?->currency ?? Settings::get('billing.currency', config('billing.currency'));
        $rate = $invoice ? $invoice->tax_rate : ($client ? (app(TaxService::class)->rateFor($client)?->rate ?? 0) : 0);

        return [
            'invoice' => $invoice,
            'client' => $client,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company_name']),
            'currencies' => Money::currencies(),
            'currency' => $currency,
            'taxRate' => $rate,
            'taxName' => $invoice?->tax_name,
            'rows' => $this->rowsForForm($invoice?->items, $currency),
            'catalog' => $this->catalog(),
            'digitsMap' => $this->digitsMap(),
            'defaultTerms' => Settings::get('billing.terms'),
        ];
    }
}
