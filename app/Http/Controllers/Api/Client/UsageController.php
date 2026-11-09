<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\UsageSummaryResource;
use App\Services\UsageService;
use Illuminate\Http\Request;

/** Read-only usage of the token owner's own services: what has been used this period and what it is estimated to cost. */
class UsageController extends ApiController
{
    public function show(Request $request, string $service, UsageService $usage): UsageSummaryResource
    {
        $model = $this->client($request)->services()->with('product')->where('uuid', $service)->firstOrFail();

        return new UsageSummaryResource($usage->currentPeriodSummary($model));
    }
}
