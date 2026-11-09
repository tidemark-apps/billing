<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Plugins\GatewayRegistry;
use App\Support\CheckoutContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pay an invoice through any enabled gateway. Addressed by the invoice's unguessable UUID so the link in the
 * invoice email works without signing in; the portal and the API use the same endpoints.
 */
class PayController extends Controller
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    public function start(Request $request, Invoice $invoice): RedirectResponse
    {
        $data = $request->validate(['gateway' => ['required', 'string']]);
        if (! $invoice->isPayable()) {
            return redirect()->route('invoice.public', $invoice->uuid)->with('error', 'This invoice cannot be paid.');
        }
        $key = $data['gateway'];
        $gateway = $this->gateways->get($key);
        if (! $gateway || ! $this->gateways->isEnabled($key) || ! $gateway->supportsCurrency($invoice->currency)) {
            return back()->with('error', 'That payment method is not available for this invoice.');
        }

        $result = $gateway->checkout($invoice, new CheckoutContext(
            successUrl: route('pay.return', [$invoice->uuid, $key]),
            cancelUrl: route('pay.cancel', $invoice->uuid),
            webhookUrl: route('webhooks.gateway', $key),
            customerEmail: $invoice->client->email,
        ));

        return $result->failed()
            ? back()->with('error', $result->error ?: 'The payment provider could not start the payment.')
            : redirect()->away($result->redirectUrl);
    }

    public function complete(Request $request, Invoice $invoice, string $gateway): RedirectResponse
    {
        $driver = $this->gateways->get($gateway);
        $message = null;
        if ($driver) {
            try {
                $driver->complete($request, $invoice);
            } catch (\Throwable $e) {
                report($e);
                $message = 'We could not confirm the payment yet. If you were charged, it will appear shortly.';
            }
        }
        $invoice->refresh();

        return redirect()->route('invoice.public', $invoice->uuid)->with(
            $message ? 'error' : 'status',
            $message ?? ($invoice->status->value === 'paid' ? 'Payment received. Thank you!' : 'Thanks. Your payment is being confirmed and will show here shortly.'),
        );
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        return redirect()->route('invoice.public', $invoice->uuid)->with('error', 'Payment was cancelled. You have not been charged.');
    }
}
