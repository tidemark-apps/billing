<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Events\ClientCreated;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\InternalAccounts;
use App\Services\Registration;
use App\Services\TaxService;
use App\Support\Countries;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientController extends ApiController
{
    public function index(Request $request)
    {
        $query = Client::query()->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($q = $request->query('q')) {
            $query->where(fn ($w) => $w->whereContains('name', $q)->orWhereContains('email', $q)->orWhereContains('company_name', $q));
        }

        return $this->paginate($query, ClientResource::class, $request);
    }

    public function store(Request $request, Registration $registration)
    {
        $data = $request->validate($this->rules() + ['password' => ['nullable', Password::min(8)], 'send_welcome' => ['nullable', 'boolean']]);
        $fields = $this->fields($data);
        if (! empty($data['password'])) {
            $request->validate(['email' => ['unique:users,email']]);
            $client = $registration->createClient($fields, $data['password'], (bool) ($data['send_welcome'] ?? false))['client'];
        } else {
            $client = Client::create($fields + ['currency' => Settings::get('billing.currency', config('billing.currency'))]);
            event(new ClientCreated($client));
        }

        $this->audit('client.created', $client, 'Created client '.$client->displayName());

        return (new ClientResource($client->refresh()))->response()->setStatusCode(201);
    }

    public function show(Client $client): ClientResource
    {
        return new ClientResource($client);
    }

    public function update(Request $request, Client $client): ClientResource
    {
        $data = $request->validate($this->rules(true));
        $fields = $this->fields($data);
        $internal = $fields['is_internal'] ?? null;
        unset($fields['is_internal']);
        $client->update($fields);
        if ($internal !== null) {
            app(InternalAccounts::class)->set($client, (bool) $internal, $request->user());
        }
        $this->audit('client.updated', $client);

        return new ClientResource($client->refresh());
    }

    public function destroy(Request $request, Client $client)
    {
        $this->requireAdmin($request, 'Only administrators can delete clients.');
        if ($client->services()->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value, ServiceStatus::Pending->value])->exists()
            || $client->invoices()->where('status', InvoiceStatus::Open->value)->exists()) {
            throw new \DomainException('This client has live services or unpaid invoices. Close those first.');
        }
        $this->audit('client.deleted', $client, 'Deleted client '.$client->displayName());
        $client->delete();

        return response()->noContent();
    }

    /** The tax rate that would apply to this client's invoices (by country/region), and its currency. */
    public function taxRate(Client $client, TaxService $tax)
    {
        $rate = $tax->rateFor($client);

        return response()->json(['data' => ['rate' => $rate?->rate ?? 0, 'name' => $rate?->name, 'currency' => $client->currency]]);
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $partial = false): array
    {
        $s = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$s, 'string', 'max:255'],
            'email' => [$s, 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2', fn ($a, $v, $fail) => isset(Countries::all()[strtoupper((string) $v)]) || $fail('The selected country is invalid.')],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'size:3', fn ($a, $v, $fail) => isset(Money::currencies()[strtoupper((string) $v)]) || $fail('The selected currency is invalid.')],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'closed'])],
            'is_internal' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @param  array<string, mixed>  $data @return array<string, mixed> */
    private function fields(array $data): array
    {
        $fields = collect($data)->only(array_keys($this->rules()))->all();
        foreach (['country', 'currency'] as $k) {
            if (! empty($fields[$k])) {
                $fields[$k] = strtoupper($fields[$k]);
            }
        }

        return $fields;
    }
}
