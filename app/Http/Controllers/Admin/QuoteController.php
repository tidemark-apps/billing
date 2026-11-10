<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuoteStatus;
use App\Http\Controllers\Admin\Concerns\HandlesLineItems;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Quote;
use App\Services\QuoteService;
use App\Services\TaxService;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteController extends Controller
{
    use HandlesLineItems;

    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(QuoteStatus::class)],
            'client_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'view' => ['nullable', Rule::enum(QuoteStatus::class)],
        ]);
        $view = $filters['view'] ?? 'all';
        unset($filters['view']);
        $query = Quote::with('client')
            ->when($view !== 'all', fn ($q) => $q->where('status', $view))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->whereContains('number', $term)->orWhereContains('title', $term)
                    ->orWhereHas('client', fn ($c) => $c->whereContains('name', $term)->orWhereContains('company_name', $term)));
            });

        return view('admin.quotes.index', [
            'quotes' => $query->latest('id')->paginate(25)->withQueryString(),
            'filters' => $filters,
            'view' => $view,
            'counts' => ['all' => Quote::count()] + Quote::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all()
                + collect(QuoteStatus::cases())->mapWithKeys(fn ($c) => [$c->value => 0])->all(),
            'awaiting' => Quote::external()->where('status', QuoteStatus::Sent->value)->selectRaw('currency, sum(total) as due')->groupBy('currency')->pluck('due', 'currency'),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company_name']),
        ]);
    }

    public function create(Request $request)
    {
        $client = $request->integer('client') ? Client::find($request->integer('client')) : null;

        return view('admin.quotes.form', $this->formData(null, $client));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $currency = strtoupper($data['currency']);

        try {
            $quote = $this->quotes->create(Client::findOrFail($data['client_id']), $this->parseItems($data['items'], $currency, true), [
                'currency' => $currency,
                'title' => $data['title'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'send' => $request->input('action') === 'send',
            ]);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        Activity::log('quote.created', $quote, 'Created quote '.$quote->displayNumber());

        return redirect()->route('admin.quotes.show', $quote)->with('status', $quote->status === QuoteStatus::Draft ? 'Draft saved.' : 'Quote sent to the client.');
    }

    public function show(Quote $quote)
    {
        $quote->load('client', 'items.product', 'invoice');

        return view('admin.quotes.show', ['quote' => $quote]);
    }

    public function edit(Quote $quote)
    {
        if ($quote->status !== QuoteStatus::Draft) {
            return redirect()->route('admin.quotes.show', $quote)->with('error', 'Only draft quotes can be edited.');
        }

        return view('admin.quotes.form', $this->formData($quote, $quote->client));
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Draft) {
            return redirect()->route('admin.quotes.show', $quote)->with('error', 'Only draft quotes can be edited.');
        }
        $data = $this->validated($request);
        $currency = strtoupper($data['currency']);

        try {
            $attrs = [
                'currency' => $currency,
                'title' => $data['title'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ];
            if ((int) $data['client_id'] !== $quote->client_id) {
                $rate = app(TaxService::class)->rateFor(Client::findOrFail($data['client_id']));
                $attrs += ['client_id' => (int) $data['client_id'], 'tax_name' => $rate?->name, 'tax_rate' => $rate?->rate ?? 0];
            }
            $quote->update($attrs);
            $this->quotes->setItems($quote, $this->parseItems($data['items'], $currency, true));
            if ($request->input('action') === 'send') {
                $this->quotes->send($quote->refresh());
            }
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        Activity::log('quote.updated', $quote, 'Updated quote '.$quote->displayNumber());

        return redirect()->route('admin.quotes.show', $quote)->with('status', $quote->refresh()->status === QuoteStatus::Draft ? 'Draft saved.' : 'Quote sent to the client.');
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Draft) {
            return back()->with('error', 'Only draft quotes can be deleted.');
        }
        $quote->items()->delete();
        $quote->delete();
        Activity::log('quote.deleted', null, 'Deleted draft quote #'.$quote->id);

        return redirect()->route('admin.quotes.index')->with('status', 'Draft deleted.');
    }

    public function send(Quote $quote): RedirectResponse
    {
        return $this->act(fn () => $this->quotes->send($quote), $quote, 'quote.sent', 'Quote sent to '.$quote->client->email.'.');
    }

    public function accept(Quote $quote): RedirectResponse
    {
        return $this->act(fn () => $this->quotes->accept($quote, auth()->user()->name.' (on behalf of client)'), $quote, 'quote.accepted', 'Quote marked as accepted.');
    }

    public function decline(Quote $quote): RedirectResponse
    {
        return $this->act(fn () => $this->quotes->decline($quote), $quote, 'quote.declined', 'Quote marked as declined.');
    }

    public function convert(Quote $quote): RedirectResponse
    {
        try {
            $invoice = $this->quotes->convert($quote);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log('quote.converted', $quote, 'Converted quote '.$quote->displayNumber().' to invoice '.$invoice->displayNumber());

        return redirect()->route('admin.invoices.show', $invoice)->with('status', 'Quote converted to invoice '.$invoice->displayNumber().'.');
    }

    public function duplicate(Quote $quote): RedirectResponse
    {
        $quote->load('items');
        $copy = $this->quotes->create($quote->client, $quote->items->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => $i->quantity,
            'unit_price' => $i->unit_price,
            'taxable' => $i->taxable,
            'product_id' => $i->product_id,
            'billing_cycle' => $i->billing_cycle?->value,
            'label' => $i->label,
        ])->all(), [
            'currency' => $quote->currency,
            'title' => $quote->title,
            'notes' => $quote->notes,
            'terms' => $quote->terms,
        ]);
        Activity::log('quote.duplicated', $copy, 'Duplicated quote '.$quote->displayNumber());

        return redirect()->route('admin.quotes.edit', $copy)->with('status', 'Copy created as a draft.');
    }

    private function act(\Closure $do, Quote $quote, string $activity, string $message): RedirectResponse
    {
        try {
            $do();
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        Activity::log($activity, $quote, $message);

        return back()->with('status', $message);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'currency' => ['required', 'string', 'size:3'],
            'title' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ] + $this->itemRules(), $this->formMessages());
    }

    /** @return array<string, mixed> */
    private function formData(?Quote $quote, ?Client $client): array
    {
        $currency = $quote?->currency ?? $client?->currency ?? Settings::get('billing.currency', config('billing.currency'));
        $rate = $quote ? $quote->tax_rate : ($client ? (app(TaxService::class)->rateFor($client)?->rate ?? 0) : 0);

        return [
            'quote' => $quote,
            'client' => $client,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'company_name']),
            'currencies' => Money::currencies(),
            'currency' => $currency,
            'taxRate' => $rate,
            'taxName' => $quote?->tax_name,
            'rows' => $this->rowsForForm($quote?->items, $currency),
            'catalog' => $this->catalog(),
            'digitsMap' => $this->digitsMap(),
            'validUntil' => $quote?->valid_until?->toDateString() ?? now()->addDays((int) Settings::get('billing.quote_valid_days', 30))->toDateString(),
        ];
    }
}
