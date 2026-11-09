<?php

namespace App\Http\Controllers\Portal;

use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Services\PaymentMethods;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The client area's "Payment methods" page. Every lookup is scoped to the signed-in client; anything else is a 404. */
class PaymentMethodController extends PortalController
{
    public function __construct(private readonly PaymentMethods $methods) {}

    public function index()
    {
        $gateways = $this->methods->gateways($this->client()->currency);

        return view('portal.payment-methods.index', [
            'methods' => $this->methods->forClient($this->client()),
            'gateways' => $gateways,
            'titles' => collect($gateways)->mapWithKeys(fn ($g, $k) => [$k => app(GatewayRegistry::class)->title($k)]),
        ]);
    }

    /** Send the customer to the gateway's hosted setup page. */
    public function setup(Request $request): RedirectResponse
    {
        $data = $request->validate(['gateway' => ['required', 'string'], 'autopay' => ['nullable', 'boolean']]);
        $client = $this->client();
        $gateway = $this->methods->gateways($client->currency)[$data['gateway']] ?? null;
        if (! $gateway) {
            return back()->with('error', 'That payment provider is not available.');
        }
        $query = $request->boolean('autopay') ? ['autopay' => 1] : [];
        $result = $this->methods->beginSetup($client, $data['gateway'], new CheckoutContext(
            successUrl: route('portal.payment-methods.return', [$data['gateway']] + $query),
            cancelUrl: route('portal.payment-methods.index'),
            webhookUrl: route('webhooks.gateway', $data['gateway']),
            customerEmail: $client->email,
        ));

        return $result->failed()
            ? back()->with('error', $result->error ?: 'The payment provider could not start the setup.')
            : redirect()->away($result->redirectUrl);
    }

    public function complete(Request $request, string $gateway): RedirectResponse
    {
        $client = $this->client();
        $driver = $this->methods->gateway($gateway);
        if (! $driver) {
            abort(404);
        }
        try {
            $data = $driver->completeSetup($request, $client);
            if (! $data) {
                return redirect()->route('portal.payment-methods.index')->with('error', 'The payment method was not saved.');
            }
            $method = $this->methods->store($client, $gateway, $data, $request->boolean('autopay'));
        } catch (\DomainException $e) {
            return redirect()->route('portal.payment-methods.index')->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('portal.payment-methods.index')->with('error', 'We could not confirm the payment method yet. If it does not appear shortly, try again.');
        }

        return redirect()->route('portal.payment-methods.index')->with('status', $method->displayName().' saved.');
    }

    public function makeDefault(string $method): RedirectResponse
    {
        $m = $this->method($method);
        $this->methods->makeDefault($m);

        return back()->with('status', $m->displayName().' is now your default method.');
    }

    public function autopay(Request $request, string $method): RedirectResponse
    {
        $m = $this->method($method);
        $on = $request->boolean('autopay');
        $this->methods->setAutopay($m, $on);

        return back()->with('status', $on ? 'Automatic payment is on for '.$m->displayName().'.' : 'Automatic payment is off for '.$m->displayName().'.');
    }

    public function destroy(string $method): RedirectResponse
    {
        $m = $this->method($method);
        $name = $m->displayName();
        $ok = $this->methods->remove($m);

        return back()->with($ok ? 'status' : 'error', $ok ? $name.' removed.' : $name.' was removed from your account, but the provider could not confirm the deletion.');
    }

    /** Pay an invoice with a saved method (used from the portal invoice page and the public invoice page for a signed-in client). */
    public function pay(Request $request, string $invoice): RedirectResponse
    {
        $data = $request->validate(['method' => ['required', 'string']]);
        $inv = $this->invoice($invoice)->load('client');
        $m = $this->method($data['method']);
        if (! $inv->isPayable()) {
            return back()->with('error', 'This invoice cannot be paid.');
        }
        $result = $this->methods->pay($m, $inv);
        $back = redirect()->route('portal.invoices.show', $inv);

        return match ($result->status) {
            ChargeResult::SUCCESS => $back->with('status', 'Payment received. Thank you!'),
            ChargeResult::PENDING => $back->with('status', 'Your payment is processing and will show here when it clears.'),
            ChargeResult::REQUIRES_ACTION => $back->with('error', 'Your bank needs you to confirm this payment. Please pay with the provider\'s checkout instead.'),
            default => $back->with('error', 'The payment failed: '.($result->message ?: 'declined').'.'),
        };
    }

    private function method(string $id): PaymentMethod
    {
        return PaymentMethod::active()->where('client_id', $this->client()->id)->where('uuid', $id)->firstOrFail();
    }
}
