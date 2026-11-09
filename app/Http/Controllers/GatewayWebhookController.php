<?php

namespace App\Http\Controllers;

use App\Plugins\GatewayRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** POST /webhooks/gateways/{gateway}: hands the raw request to the gateway plugin, which verifies and records it. */
class GatewayWebhookController extends Controller
{
    public function __invoke(Request $request, GatewayRegistry $gateways, string $gateway): Response
    {
        $driver = $gateways->get($gateway);
        if (! $driver) {
            return response('Unknown gateway', 404);
        }

        try {
            return $driver->webhook($request);
        } catch (\Throwable $e) {
            report($e);

            return response('Webhook error', 500);
        }
    }
}
