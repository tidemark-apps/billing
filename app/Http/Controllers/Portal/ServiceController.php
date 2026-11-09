<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ServiceStatus;
use App\Services\ProvisioningService;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServiceController extends PortalController
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function index(Request $request)
    {
        $services = $this->client()->services()->with('product')->latest()->paginate(20);

        return view('portal.services.index', ['services' => $services]);
    }

    public function show(string $service)
    {
        $service = $this->service($service)->load('product');
        $module = $this->provisioning->moduleFor($service);

        return view('portal.services.show', [
            'service' => $service,
            'actions' => $module && $service->status === ServiceStatus::Active ? $module->clientActions() : [],
            'hasSecrets' => ! empty($service->secrets),
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
}
