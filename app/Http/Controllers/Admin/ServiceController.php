<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Events\ServiceCreated;
use App\Events\TrialStarted;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Services\BillingRunner;
use App\Services\InternalAccounts;
use App\Services\Notifier;
use App\Services\PlanChangeService;
use App\Services\ProvisioningService;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function __construct(private readonly ProvisioningService $provisioning, private readonly PlanChangeService $planChanges) {}

    public function index(Request $request)
    {
        $services = Service::with(['client', 'product'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', $request->integer('product')))
            ->when($request->filled('client'), fn ($q) => $q->where('client_id', $request->integer('client')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = (string) $request->string('q');
                $q->where(fn ($w) => $w->whereContains('label', $term)->orWhereHas('client', fn ($c) => $c->whereContains('name', $term)->orWhereContains('company_name', $term)->orWhereContains('email', $term)));
            })
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.services.index', [
            'services' => $services,
            'products' => Product::orderBy('name')->pluck('name', 'id'),
            'statuses' => collect(ServiceStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all(),
        ]);
    }

    public function create(Request $request)
    {
        return view('admin.services.create', [
            'clients' => Client::orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => $c->displayName().' ('.$c->email.')'])->all(),
            'products' => Product::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'trialProducts' => Product::where('is_active', true)->where('trial_days', '>', 0)->pluck('trial_days', 'id')->all(),
            'cycles' => BillingCycle::options(),
            'selectedClient' => $request->integer('client') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'product_id' => ['required', 'exists:products,id'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'label' => ['nullable', 'string', 'max:255'],
            'next_due_date' => ['nullable', 'date'],
            'activate' => ['nullable', 'boolean'],
            'skip_trial' => ['nullable', 'boolean'],
        ]);
        $client = Client::findOrFail($data['client_id']);
        $product = Product::with('prices')->findOrFail($data['product_id']);
        $cycle = BillingCycle::from($data['billing_cycle']);
        $price = $product->priceFor($cycle, $client->currency);

        if (($data['amount'] ?? '') === '' && ! $price) {
            return back()->withInput()->withErrors(['amount' => 'This product has no price for that cycle. Enter a price.']);
        }
        $currency = $price?->currency ?? $client->currency;
        $amount = ($data['amount'] ?? '') !== '' ? Money::toMinor($data['amount'], $currency) : $price->amount;

        // A product with a trial starts as one (a trial is live from the start, so it is activated), unless staff skip it.
        $trial = $cycle->isRecurring() && ! $request->boolean('skip_trial') && $product->trialAvailableTo($client);
        $trialEnds = $trial ? now()->addDays($product->trial_days)->startOfDay() : null;

        $service = Service::create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'status' => ServiceStatus::Pending,
            'label' => $data['label'] ?? null,
            'billing_cycle' => $cycle,
            'currency' => $currency,
            ...InternalAccounts::pricing($client, $amount),
            'next_due_date' => $trialEnds ?? ($data['next_due_date'] ?? null),
            'trial_ends_at' => $trialEnds,
        ]);
        event(new ServiceCreated($service));
        Activity::log('service.created', $service, $trial ? 'Created service manually with a '.$product->trial_days.'-day trial' : 'Created service manually');
        if ($trial) {
            event(new TrialStarted($service, ['days' => $product->trial_days]));
            app(Notifier::class)->trialStarted($service);
        }

        if ($trial || $request->boolean('activate')) {
            $service = $this->provisioning->activate($service);
            if ($service->status !== ServiceStatus::Active) {
                return redirect()->route('admin.services.show', $service)->with('error', 'Service created but provisioning failed: '.$service->provision_error);
            }
        }

        return redirect()->route('admin.services.show', $service)->with('status', $trial ? 'Service created. Its '.$product->trial_days.'-day trial has started.' : 'Service created.');
    }

    public function show(Service $service)
    {
        return $this->render($service);
    }

    /** @param  array<string, mixed>|null  $preview */
    private function render(Service $service, bool $reveal = false, ?array $preview = null, array $selected = [])
    {
        $service->load(['client', 'product', 'server', 'logs' => fn ($q) => $q->with('user')->limit(50)]);
        $module = $this->provisioning->moduleFor($service);

        return view('admin.services.show', [
            'service' => $service,
            'module' => $module,
            'servers' => $module?->usesServers() ? Server::where('module', $module->key())->orderBy('name')->pluck('name', 'id')->all() : [],
            'cycles' => BillingCycle::options(),
            'reveal' => $reveal,
            'planOptions' => $this->planChanges->changeable($service) ? $this->planChanges->options($service, true) : collect(),
            'canChangePlan' => $this->planChanges->changeable($service),
            'scheduledChange' => $service->scheduledChange()?->load(['toProduct']),
            'history' => $service->planChanges()->with(['fromProduct', 'toProduct', 'invoice'])->get(),
            'preview' => $preview,
            'selected' => $selected,
        ]);
    }

    /** Secrets are only rendered in response to this explicit, logged request. */
    public function reveal(Service $service)
    {
        Activity::log('service.secrets_revealed', $service, 'Revealed secrets for '.$service->title());

        return $this->render($service, true);
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'next_due_date' => ['nullable', 'date'],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'server_id' => ['nullable', 'exists:servers,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $amount = Money::toMinor($data['amount'], $service->currency);
        $cycle = BillingCycle::from($data['billing_cycle']);
        $billingChanged = ($amount !== $service->amount && ! (InternalAccounts::is($service->client) && $amount === 0)) || $cycle !== $service->billing_cycle;

        $service->update([
            'label' => $data['label'] ?? null,
            ...InternalAccounts::reprice($service, $amount),
            'billing_cycle' => $cycle,
            'next_due_date' => $data['next_due_date'] ?? null,
            'server_id' => $data['server_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        Activity::log('service.updated', $service);
        if ($billingChanged) {
            $this->provisioning->changePackage($service);
        }

        return back()->with('status', 'Service saved.');
    }

    public function extendTrial(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:365']]);
        if (! $service->onTrial()) {
            return back()->with('error', 'This service is not on a trial.');
        }
        $ends = $service->trial_ends_at->copy()->addDays((int) $data['days']);
        $service->update(['trial_ends_at' => $ends, 'next_due_date' => $ends, 'trial_reminded_at' => null]);
        Activity::log('service.trial_extended', $service, 'Trial extended by '.$data['days'].' days to '.$ends->toDateString());

        return back()->with('status', 'Trial extended to '.$ends->format('j M Y').'.');
    }

    public function endTrial(Service $service, BillingRunner $runner): RedirectResponse
    {
        if (! $service->onTrial()) {
            return back()->with('error', 'This service is not on a trial.');
        }
        $runner->convertTrialNow($service);
        Activity::log('service.trial_ended', $service, 'Trial ended early and invoiced');

        return back()->with('status', 'Trial ended. The first invoice has been created.');
    }

    public function previewPlanChange(Request $request, Service $service)
    {
        [$product, $cycle, $timing] = $this->planInput($request);
        try {
            $preview = $this->planChanges->preview($service, $product, $cycle, $timing, true);
        } catch (\DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return $this->render($service, false, $preview, ['plan' => $product->id.':'.$cycle->value, 'timing' => $timing]);
    }

    public function changePlan(Request $request, Service $service): RedirectResponse
    {
        [$product, $cycle, $timing] = $this->planInput($request);
        try {
            $change = $this->planChanges->change($service, $product, $cycle, $timing, 'admin');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.services.show', $service)->with('status', $change->status === 'scheduled' ? 'Plan change scheduled for '.$change->effective_date->format('j M Y').'.' : 'Plan changed.'.($change->invoice_id ? ' An invoice was created for the difference.' : ''));
    }

    public function cancelScheduledChange(Service $service): RedirectResponse
    {
        $cancelled = $this->planChanges->cancelScheduled($service);

        return back()->with($cancelled ? 'status' : 'error', $cancelled ? 'Scheduled change cancelled.' : 'There is no scheduled change.');
    }

    /** @return array{0: Product, 1: BillingCycle, 2: ?string} */
    private function planInput(Request $request): array
    {
        $data = $request->validate([
            'plan' => ['required', 'regex:/^\d+:[a-z_]+$/'],
            'timing' => ['nullable', Rule::in(['', 'immediate', 'renewal'])],
        ]);
        [$id, $cycle] = explode(':', $data['plan']);

        return [Product::findOrFail((int) $id), BillingCycle::tryFrom($cycle) ?? abort(422), ($data['timing'] ?? '') ?: null];
    }

    public function activate(Service $service): RedirectResponse
    {
        return $this->lifecycle($service, 'activate', fn () => $this->provisioning->activate($service), 'activated', fn (Service $s) => $s->status === ServiceStatus::Active);
    }

    public function suspend(Request $request, Service $service): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        return $this->lifecycle($service, 'suspend', fn () => $this->provisioning->suspend($service, $reason), 'suspended', fn (Service $s) => $s->status === ServiceStatus::Suspended);
    }

    public function unsuspend(Service $service): RedirectResponse
    {
        return $this->lifecycle($service, 'unsuspend', fn () => $this->provisioning->unsuspend($service), 'unsuspended', fn (Service $s) => $s->status === ServiceStatus::Active);
    }

    public function terminate(Service $service): RedirectResponse
    {
        return $this->lifecycle($service, 'terminate', fn () => $this->provisioning->terminate($service), 'terminated', fn (Service $s) => $s->status === ServiceStatus::Terminated);
    }

    public function cancel(Request $request, Service $service): RedirectResponse
    {
        $data = $request->validate(['immediately' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $now = $request->boolean('immediately');

        return $this->lifecycle($service, 'cancel', fn () => $this->provisioning->cancel($service, $now, $data['reason'] ?? null), $now ? 'cancelled' : 'set to cancel at the end of the billing period', fn (Service $s) => $now ? $s->status === ServiceStatus::Cancelled : $s->cancel_at_period_end);
    }

    public function action(Request $request, Service $service): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'string', 'max:64']])['action'];
        $result = $this->provisioning->runAction($service, $action, 'admin');
        Activity::log('service.action', $service, "Ran action $action", ['success' => $result->success]);
        if ($result->success && $result->redirect) {
            return redirect()->away($result->redirect);
        }

        return back()->with($result->success ? 'status' : 'error', $result->message ?: ($result->success ? 'Done.' : 'The action failed.'));
    }

    private function lifecycle(Service $service, string $name, \Closure $run, string $past, \Closure $ok): RedirectResponse
    {
        $service = $run();
        Activity::log('service.'.$name, $service);

        return back()->with($ok($service) ? 'status' : 'error', $ok($service) ? "Service {$past}." : 'Could not '.$name.' the service: '.($service->provision_error ?: 'not allowed in its current state.'));
    }
}
