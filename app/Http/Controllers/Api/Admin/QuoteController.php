<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BillingCycle;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\QuoteResource;
use App\Models\Client;
use App\Models\Quote;
use App\Services\QuoteService;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuoteController extends ApiController
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function index(Request $request)
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(QuoteStatus::class)],
            'client_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = Quote::latest('id')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['client_id'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->whereContains('number', $term)->orWhereContains('title', $term)
                ->orWhereHas('client', fn ($c) => $c->whereContains('name', $term)->orWhereContains('company_name', $term))));

        return $this->paginate($query, QuoteResource::class, $request);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules(true));
        $quote = $this->quotes->create(Client::findOrFail($data['client_id']), $data['items'], collect($data)->except(['client_id', 'items'])->all());
        $this->audit('quote.created', $quote, 'Created quote '.$quote->displayNumber());

        return (new QuoteResource($quote->load('items')))->response()->setStatusCode(201);
    }

    public function show(Quote $quote): QuoteResource
    {
        return new QuoteResource($quote->load('items', 'invoice', 'client'));
    }

    public function update(Request $request, Quote $quote): QuoteResource
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new \DomainException('Only draft quotes can be edited.');
        }
        $data = $request->validate($this->rules(false));
        $attrs = collect($data)->only(['title', 'valid_until', 'notes', 'terms'])->all();
        if (isset($data['currency'])) {
            $attrs['currency'] = strtoupper($data['currency']);
        }
        if (isset($data['client_id']) && (int) $data['client_id'] !== $quote->client_id) {
            $rate = app(TaxService::class)->rateFor(Client::findOrFail($data['client_id']));
            $attrs += ['client_id' => (int) $data['client_id'], 'tax_name' => $rate?->name, 'tax_rate' => $rate?->rate ?? 0];
        }
        $quote->update($attrs);
        if (isset($data['items'])) {
            $this->quotes->setItems($quote, $data['items']);
        } else {
            $this->quotes->recalculate($quote);
        }
        $this->audit('quote.updated', $quote, 'Updated quote '.$quote->displayNumber());

        return new QuoteResource($quote->refresh()->load('items'));
    }

    public function destroy(Quote $quote)
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw new \DomainException('Only draft quotes can be deleted.');
        }
        $quote->items()->delete();
        $quote->delete();
        $this->audit('quote.deleted', null, 'Deleted draft quote #'.$quote->id);

        return response()->noContent();
    }

    public function send(Quote $quote): QuoteResource
    {
        $quote = $this->quotes->send($quote);
        $this->audit('quote.sent', $quote, 'Sent quote '.$quote->displayNumber());

        return new QuoteResource($quote->load('items'));
    }

    /** Turn the quote into an invoice (and pending services for product lines). */
    public function convert(Quote $quote)
    {
        $invoice = $this->quotes->convert($quote);
        $this->audit('quote.converted', $quote, 'Converted quote '.$quote->displayNumber().' to invoice '.$invoice->displayNumber());

        return (new InvoiceResource($invoice->load('items')))->response()->setStatusCode(201);
    }

    public function accept(Quote $quote): QuoteResource
    {
        $quote = $this->quotes->accept($quote, 'Recorded by staff');
        $this->audit('quote.accepted', $quote, 'Marked quote '.$quote->displayNumber().' as accepted');

        return new QuoteResource($quote->load('items', 'invoice'));
    }

    public function decline(Quote $quote): QuoteResource
    {
        $quote = $this->quotes->decline($quote);
        $this->audit('quote.declined', $quote, 'Marked quote '.$quote->displayNumber().' as declined');

        return new QuoteResource($quote->load('items'));
    }

    /** Copy a quote (any status) into a new draft. */
    public function duplicate(Quote $quote)
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
        ])->all(), ['currency' => $quote->currency, 'title' => $quote->title, 'notes' => $quote->notes, 'terms' => $quote->terms]);
        $this->audit('quote.duplicated', $copy, 'Duplicated quote '.$quote->displayNumber());

        return (new QuoteResource($copy->load('items')))->response()->setStatusCode(201);
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
            'items.*.billing_cycle' => ['nullable', Rule::enum(BillingCycle::class)],
            'items.*.label' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'size:3'],
            'title' => ['nullable', 'string', 'max:255'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'send' => [$creating ? 'nullable' : 'prohibited', 'boolean'],
        ];
    }
}
