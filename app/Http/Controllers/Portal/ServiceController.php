<?php

namespace App\Http\Controllers\Portal;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Services\PlanChangeService;
use App\Services\ProvisioningService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceController extends PortalController
{
    public function __construct(private readonly ProvisioningService $provisioning, private readonly PlanChangeService $planChanges) {}

    public function index(Request $request)
    {
        $base = $this->client()->services();
        $by = (clone $base)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $counts = [
            '' => (int) $by->sum(),
            'active' => (int) ($by['active'] ?? 0),
            'pending' => (int) ($by['pending'] ?? 0),
            'suspended' => (int) ($by['suspended'] ?? 0),
            'ended' => (int) (($by['cancelled'] ?? 0) + ($by['terminated'] ?? 0)),
        ];
        $status = (string) $request->query('status');
        $services = (clone $base)->with('product')
            ->when($status === 'ended', fn ($q) => $q->whereIn('status', ['cancelled', 'terminated']))
            ->when(in_array($status, ['active', 'pending', 'suspended'], true), fn ($q) => $q->where('status', $status))
            ->latest()->paginate(20)->withQueryString();

        return view('portal.services.index', ['services' => $services, 'status' => $status, 'counts' => $counts]);
    }

    public function show(string $service)
    {
        return $this->render($this->service($service));
    }

    /** @param  array<string, mixed>|null  $preview */
    private function render(Service $service, ?array $preview = null, array $selected = [])
    {
        $service->load('product');
        $canChange = $this->planChanges->customerCanChange($service);
        $module = $this->provisioning->moduleFor($service);

        return view('portal.services.show', [
            'service' => $service,
            'actions' => $module && $service->status === ServiceStatus::Active ? $module->clientActions() : [],
            'hasSecrets' => ! empty($service->secrets),
            'canChangePlan' => $canChange,
            'planOptions' => $canChange ? $this->planChanges->options($service) : collect(),
            'scheduledChange' => $service->scheduledChange()?->load('toProduct'),
            'preview' => $preview,
            'selected' => $selected,
        ]);
    }

    public function reveal(string $service): RedirectResponse
    {
        $service = $this->service($service);
        Activity::log('service.secrets_revealed', $service, 'Customer revealed service credentials');

        return redirect()->route('portal.services.show', $service)->with('revealed', $service->secrets ?? []);
    }

    public function action(string $service, string $action): RedirectResponse
    {
        $service = $this->service($service);
        $result = $this->provisioning->runAction($service, $action, 'portal');
        if ($result->success && $result->redirect) {
            return redirect()->away($result->redirect);
        }

        return redirect()->route('portal.services.show', $service)->with($result->success ? 'status' : 'error', $result->message ?: ($result->success ? 'Done.' : 'That did not work.'));
    }

    public function cancel(Request $request, string $service): RedirectResponse
    {
        $service = $this->service($service);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        if (! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending], true)) {
            return back()->with('error', 'This service cannot be cancelled.');
        }
        $this->provisioning->cancel($service, false, $data['reason'] ?? null);
        Activity::log('service.cancel_requested', $service, 'Customer requested cancellation');

        return redirect()->route('portal.services.show', $service)->with('status', $service->refresh()->cancel_at_period_end ? 'Cancellation scheduled for the end of the billing period.' : 'Service cancelled.');
    }

    public function previewPlan(Request $request, string $service)
    {
        $service = $this->service($service);
        try {
            [$product, $cycle] = $this->chosenPlan($request, $service);
            $preview = $this->planChanges->preview($service, $product, $cycle, null, false);
        } catch (\DomainException $e) {
            return redirect()->route('portal.services.show', $service)->with('error', $e->getMessage());
        }

        return $this->render($service, $preview, ['plan' => $product->id.':'.$cycle->value]);
    }

    public function changePlan(Request $request, string $service): RedirectResponse
    {
        $service = $this->service($service);
        try {
            [$product, $cycle] = $this->chosenPlan($request, $service);
            $change = $this->planChanges->change($service, $product, $cycle, null, 'customer');
        } catch (\DomainException $e) {
            return redirect()->route('portal.services.show', $service)->with('error', $e->getMessage());
        }
        $message = $change->status === 'scheduled'
            ? 'Your plan change is scheduled for '.$change->effective_date->format('j M Y').'.'
            : 'Your plan has been changed.'.($change->invoice_id ? ' An invoice for the difference is ready to pay.' : '');

        return redirect($change->invoice_id ? route('portal.invoices.show', $change->invoice_id) : route('portal.services.show', $service))->with('status', $message);
    }

    public function cancelPlanChange(string $service): RedirectResponse
    {
        $service = $this->service($service);
        $cancelled = $this->planChanges->cancelScheduled($service);

        return redirect()->route('portal.services.show', $service)->with($cancelled ? 'status' : 'error', $cancelled ? 'Scheduled plan change cancelled.' : 'There is no scheduled change.');
    }

    /**
     * The plan the customer picked, which must be one they are offered.
     *
     * @return array{0: Product, 1: BillingCycle}
     */
    private function chosenPlan(Request $request, Service $service): array
    {
        if (! $this->planChanges->customerCanChange($service)) {
            throw new \DomainException('Plan changes are not available for this service. Please contact us.');
        }
        $data = $request->validate(['plan' => ['required', 'regex:/^\d+:[a-z_]+$/']]);
        [$id, $cycle] = explode(':', $data['plan']);
        $option = $this->planChanges->options($service)->first(fn ($o) => $o['product']->id === (int) $id && $o['cycle']->value === $cycle);

        return $option ? [$option['product'], $option['cycle']] : throw new \DomainException('That plan is not available.');
    }
}
