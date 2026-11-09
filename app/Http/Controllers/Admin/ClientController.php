<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Enums\ServiceStatus;
use App\Events\ClientCreated;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\Registration;
use App\Support\Activity;
use App\Support\Countries;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $clients = Client::query()
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = (string) $request->string('q');
                $q->where(fn ($w) => $w->whereContains('name', $term)->orWhereContains('company_name', $term)->orWhereContains('email', $term));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount(['services as live_services_count' => fn ($q) => $q->whereIn('status', ['active', 'suspended'])])
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.clients.index', ['clients' => $clients]);
    }

    public function create()
    {
        return view('admin.clients.form', $this->formData(new Client(['status' => 'active', 'currency' => Settings::get('billing.currency', config('billing.currency'))])));
    }

    public function store(Request $request, Registration $registration): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'create_login' => ['nullable', 'boolean'],
            'password' => ['nullable', 'required_if:create_login,1', Password::min(8)],
            'send_welcome' => ['nullable', 'boolean'],
        ]);
        if ($request->boolean('create_login') && User::where('email', $data['email'])->exists()) {
            return back()->withInput()->withErrors(['email' => 'A login with this email already exists.']);
        }
        $fields = collect($data)->except(['create_login', 'password', 'send_welcome'])->all();

        if ($request->boolean('create_login')) {
            $client = $registration->createClient($fields, $data['password'], $request->boolean('send_welcome'))['client'];
        } else {
            $client = Client::create($fields);
            event(new ClientCreated($client));
        }
        Activity::log('client.created', $client, 'Created client '.$client->displayName());

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client created.');
    }

    public function show(Client $client)
    {
        $client->load(['services.product', 'invoices' => fn ($q) => $q->latest('id'), 'quotes' => fn ($q) => $q->latest('id'), 'payments' => fn ($q) => $q->latest('id'), 'users']);

        return view('admin.clients.show', ['client' => $client, 'loginUser' => $client->users->first(fn (User $u) => $u->is_active)]);
    }

    public function edit(Client $client)
    {
        return view('admin.clients.form', $this->formData($client));
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $client->update($request->validate($this->rules()));
        Activity::log('client.updated', $client);

        return redirect()->route('admin.clients.show', $client)->with('status', 'Client saved.');
    }

    public function destroy(Request $request, Client $client): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        if ($client->services()->whereIn('status', [ServiceStatus::Active->value, ServiceStatus::Suspended->value, ServiceStatus::Pending->value])->exists()
            || $client->invoices()->where('status', InvoiceStatus::Open->value)->exists()) {
            return back()->with('error', 'This client has live services or unpaid invoices. Close those first.');
        }
        Activity::log('client.deleted', $client, 'Deleted client '.$client->displayName());
        $client->delete();

        return redirect()->route('admin.clients.index')->with('status', 'Client deleted.');
    }

    public function storeContact(Request $request, Client $client): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(8)],
        ]);
        $user = User::create($data + ['role' => Role::Customer, 'client_id' => $client->id]);
        Activity::log('client.contact_added', $client, 'Added login '.$user->email);

        return redirect()->to(route('admin.clients.show', $client).'#contacts')->with('status', 'Login added.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'size:2', Rule::in(array_keys(Countries::all()))],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency' => ['required', 'string', 'size:3', Rule::in(array_keys(Money::currencies()))],
            'status' => ['required', Rule::in(['active', 'inactive', 'closed'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, mixed> */
    private function formData(Client $client): array
    {
        return ['client' => $client, 'countries' => ['' => 'Select…'] + Countries::all(), 'currencies' => Money::currencies()];
    }
}
