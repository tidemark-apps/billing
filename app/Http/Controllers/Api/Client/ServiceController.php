<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Support\PlanChangeEndpoints;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\UsageSummaryResource;
use App\Models\Service;
use App\Services\ProvisioningService;
use App\Services\UsageService;
use Illuminate\Http\Request;

class ServiceController extends ApiController
{
    use PlanChangeEndpoints;

    public function index(Request $request)
    {
        $query = $this->client($request)->services()->with('product', 'planChanges.toProduct')->latest('id');
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        return $this->paginate($query, ServiceResource::class, $request);
    }

    public function show(Request $request, string $service, ProvisioningService $provisioning, UsageService $usage)
    {
        $model = $this->find($request, $service);

        return (new ServiceResource($model))->additional(['meta' => [
            'actions' => $provisioning->moduleFor($model)?->clientActions() ?? new \stdClass,
        ] + ($request->attributes->get('api_token')?->can('usage.read') && $usage->hasMeters($model) ? ['usage' => (new UsageSummaryResource($usage->currentPeriodSummary($model)))->resolve($request)] : [])]);
    }

    public function cancel(Request $request, string $service, ProvisioningService $provisioning): ServiceResource
    {
        $data = $request->validate(['immediately' => ['nullable', 'boolean'], 'reason' => ['nullable', 'string', 'max:255']]);
        $model = $provisioning->cancel($this->find($request, $service), (bool) ($data['immediately'] ?? false), $data['reason'] ?? null);

        return new ServiceResource($model->load('product'));
    }

    /** Run a module "client action" such as a control-panel login or password reset. */
    public function action(Request $request, string $service, string $action, ProvisioningService $provisioning)
    {
        $result = $provisioning->runAction($this->find($request, $service), $action, 'portal');
        if (! $result->success) {
            return response()->json(['error' => ['code' => 'action_failed', 'message' => $result->message ?: 'The action failed.']], 422);
        }

        return response()->json(['data' => ['success' => true, 'message' => $result->message, 'redirect' => $result->redirect]]);
    }

    public function planOptions(Request $request, string $service)
    {
        return $this->planOptionsResponse($this->find($request, $service), false);
    }

    public function planPreview(Request $request, string $service)
    {
        return $this->planPreviewResponse($request, $this->find($request, $service), false);
    }

    public function planChange(Request $request, string $service)
    {
        return $this->planChangeResponse($request, $this->find($request, $service), false);
    }

    public function cancelPlanChange(Request $request, string $service)
    {
        return $this->planCancelResponse($this->find($request, $service), false);
    }

    private function find(Request $request, string $uuid): Service
    {
        return $this->client($request)->services()->with('product')->where('uuid', $uuid)->firstOrFail();
    }
}
