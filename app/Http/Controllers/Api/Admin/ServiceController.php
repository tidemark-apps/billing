<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\ServiceCreated;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\PlanChangeEndpoints;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PlanChangeResource;
use App\Http\Resources\ServiceLogResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UsageSummaryResource;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use App\Services\BillingRunner;
use App\Services\InternalAccounts;
use App\Services\OrderService;
use App\Services\ProvisioningService;
use App\Services\UsageService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceController extends ApiController
{
    use PlanChangeEndpoints;

    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function index(Request $request)
    {
        $query = Service::with('product', 'planChanges.toProduct')->latest('id');
        foreach (['status', 'client_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($q = $request->query('q')) {
            $query->whereContains('label', $q);
        }

        return $this->paginate($query, ServiceResource::class, $request);
    }

    /**
     * Create a service for a client. By default this places an order: the pending service plus its invoice.
     * With create_invoice=false it only creates the service (like "New service" in the admin UI): you can set the price
     * and next due date, and activate it straight away.
     */
    public function store(Request $request, OrderService $orders)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'send' => ['nullable', 'boolean'],
            'create_invoice' => ['nullable', 'boolean'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'activate' => ['nullable', 'boolean'],
            'trial' => ['nullable', 'boolean'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $product = Product::with('prices')->findOrFail($data['product_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);

        if ($request->boolean('create_invoice', true)) {
            $order = $orders->place($client, $product, $cycle, ['label' => $data['label'] ?? null, 'send' => $data['send'] ?? true] + (array_key_exists('trial', $data) && $data['trial'] !== null ? ['trial' => (bool) $data['trial']] : []));
            $this->audit('service.created', $order['service'], 'Ordered '.$product->name.' for '.$client->displayName());

            return response()->json(['data' => [
                'service' => (new ServiceResource($order['service']->load('product')))->resolve($request),
                'invoice' => $order['invoice'] ? (new InvoiceResource($order['invoice']->load('items')))->resolve($request) : null,
            ]], 201);
        }

        $price = $product->priceFor($cycle, $client->currency);
        if (! isset($data['amount']) && ! $price) {
            throw ValidationException::withMessages(['amount' => 'This product has no price for that cycle. Send an amount.']);
        }
        $service = Service::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'label' => $data['label'] ?? null,
            'billing_cycle' => $cycle,
            'currency' => $price?->currency ?? $client->currency,
            ...InternalAccounts::pricing($client, (int) ($data['amount'] ?? $price->amount)),
            'next_due_date' => $data['next_due_date'] ?? null,
        ]);
        event(new ServiceCreated($service));
        $this->audit('service.created', $service, 'Created service manually');
        if ($request->boolean('activate')) {
            $service = $this->provisioning->activate($service);
        }

        return response()->json(['data' => ['service' => (new ServiceResource($service->refresh()->load('product')))->resolve($request), 'invoice' => null]], 201);
    }

    public function show(Request $request, Service $service): ServiceResource
    {
        $usage = app(UsageService::class);

        return (new ServiceResource($service->load('product')))->additional(['meta' => [
            'actions' => $this->provisioning->moduleFor($service)?->adminActions() ?? new \stdClass,
            'logs' => $service->logs()->limit(20)->get(['action', 'success', 'message', 'created_at']),
        ] + ($request->attributes->get('api_token')?->can('usage.read') && $usage->hasMeters($service) ? ['usage' => (new UsageSummaryResource($usage->currentPeriodSummary($service)))->resolve($request)] : [])]);
    }

    public function update(Request $request, Service $service): ServiceResource
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'amount' => ['sometimes', 'integer', 'min:0'],
            'billing_cycle' => ['sometimes', Rule::enum(BillingCycle::class)],
            'next_due_date' => ['nullable', 'date'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
        ]);
        $billingChanged = (isset($data['amount']) && $data['amount'] !== $service->amount) || (isset($data['billing_cycle']) && $data['billing_cycle'] !== $service->billing_cycle->value);
        $fields = $data;
        if (isset($data['amount'])) {
            unset($fields['amount']);
            $fields += InternalAccounts::reprice($service, (int) $data['amount']);
        }
        $service->update($fields);
        $this->audit('service.updated', $service);
        if ($billingChanged) {
            $this->provisioning->changePackage($service);
        }

        return new ServiceResource($service->refresh()->load('product'));
    }

    /** Provisioning log: every module call made for this service, newest first. */
    public function logs(Request $request, Service $service)
    {
        return $this->paginate($service->logs()->with('user')->reorder()->latest('id'), ServiceLogResource::class, $request);
    }

    /** Decrypt and return the secrets a module stored (passwords, keys). Every call is written to the activity log. */
    public function reveal(Service $service)
    {
        $this->audit('service.secrets_revealed', $service, 'Revealed secrets for '.$service->title());

        return response()->json(['data' => ['secrets' => $service->secrets ?: new \stdClass]]);
    }

    public function activate(Service $service): ServiceResource
    {
        return $this->respond($this->provisioning->activate($service), 'activate');
    }

    public function suspend(Request $request, Service $service): ServiceResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->respond($this->provisioning->suspend($service, $data['reason'] ?? null), 'suspend');
    }

    public function unsuspend(Service $service): ServiceResource
    {
        return $this->respond($this->provisioning->unsuspend($service), 'unsuspend');
    }

    public function terminate(Service $service): ServiceResource
    {
        return $this->respond($this->provisioning->terminate($service), 'terminate');
    }

    public function cancel(Request $request, Service $service): ServiceResource
    {
        $data = $request->validate(['immediately' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);

        return $this->respond($this->provisioning->cancel($service, (bool) ($data['immediately'] ?? false), $data['reason'] ?? null), 'cancel');
    }

    /** Push the end of a running trial back by N days (the first invoice is raised on the new date). */
    public function extendTrial(Request $request, Service $service): ServiceResource
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);
        if (! $service->onTrial()) {
            throw new \DomainException('This service is not on a trial.');
        }
        $end = $service->trial_ends_at->copy()->addDays($data['days']);
        $service->update(['trial_ends_at' => $end, 'next_due_date' => $end, 'trial_reminded_at' => null]);
        $this->audit('service.trial_extended', $service, 'Trial extended by '.$data['days'].' days', ['days' => $data['days']]);

        return new ServiceResource($service->refresh()->load('product'));
    }

    /** End a running trial now: the first invoice is raised right away. */
    public function endTrial(Service $service, BillingRunner $runner): ServiceResource
    {
        if (! $service->onTrial()) {
            throw new \DomainException('This service is not on a trial.');
        }
        $runner->convertTrialNow($service);
        $this->audit('service.trial_ended', $service, 'Trial ended early');

        return new ServiceResource($service->refresh()->load('product'));
    }

    public function planOptions(Service $service)
    {
        return $this->planOptionsResponse($service->loadMissing('product'), true);
    }

    public function planPreview(Request $request, Service $service)
    {
        return $this->planPreviewResponse($request, $service->loadMissing('product'), true);
    }

    public function planChange(Request $request, Service $service)
    {
        return $this->planChangeResponse($request, $service->loadMissing('product'), true);
    }

    public function cancelPlanChange(Service $service)
    {
        return $this->planCancelResponse($service, true);
    }

    public function planChanges(Request $request, Service $service)
    {
        return $this->paginate($service->planChanges()->with('fromProduct', 'toProduct', 'invoice')->reorder()->latest('id'), PlanChangeResource::class, $request);
    }

    public function action(Service $service, string $action)
    {
        $result = $this->provisioning->runAction($service, $action, 'admin');
        $this->audit('service.action', $service, "Ran action $action", ['success' => $result->success]);
        if (! $result->success) {
            return response()->json(['error' => ['code' => 'action_failed', 'message' => $result->message ?: 'The action failed.']], 422);
        }

        return response()->json(['data' => ['success' => true, 'message' => $result->message, 'redirect' => $result->redirect]]);
    }

    private function respond(Service $service, string $action): ServiceResource
    {
        $this->audit('service.'.$action, $service);

        return new ServiceResource($service->refresh()->load('product'));
    }
}
