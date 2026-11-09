<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Services\PaymentMethods;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use Illuminate\Http\Request;

/**
 * The saved payment methods of the token owner's client, the API twin of the portal's "Payment methods" page (same service
 * calls, same rules). Gateway tokens never leave the server. Everything is scoped to the client: other clients' methods are a 404.
 */
class PaymentMethodController extends ApiController
{
    public function __construct(private readonly PaymentMethods $methods) {}

    /** Methods plus the gateways that can save new ones (meta.gateways). */
    public function index(Request $request)
    {
        $client = $this->client($request);
        $registry = app(GatewayRegistry::class);

        return PaymentMethodResource::collection($this->methods->forClient($client))->additional(['meta' => [
            'gateways' => collect($this->methods->gateways($client->currency))->map(fn ($g, $key) => [
                'key' => $key, 'title' => $registry->title($key), 'description' => $g->description(),
            ])->values()->all(),
        ]]);
    }

    /**
     * Start saving a method: returns the gateway's hosted setup URL. The customer finishes in a browser and the gateway sends
     * them back to the client area, which stores the method (they may have to sign in to the client area there).
     */
    public function setup(Request $request)
    {
        $data = $request->validate(['gateway' => ['required', 'string'], 'autopay' => ['nullable', 'boolean']]);
        $client = $this->client($request);
        if (! isset($this->methods->gateways($client->currency)[$data['gateway']])) {
            throw new \DomainException('That payment provider is not available.');
        }
        $query = $request->boolean('autopay') ? ['autopay' => 1] : [];
        $result = $this->methods->beginSetup($client, $data['gateway'], new CheckoutContext(
            successUrl: route('portal.payment-methods.return', [$data['gateway']] + $query),
            cancelUrl: route('portal.payment-methods.index'),
            webhookUrl: route('webhooks.gateway', $data['gateway']),
            customerEmail: $client->email,
        ));
        if ($result->failed()) {
            throw new \DomainException($result->error ?: 'The payment provider could not start the setup.');
        }

        return response()->json(['data' => ['redirect_url' => $result->redirectUrl]]);
    }

    public function show(Request $request, string $method): PaymentMethodResource
    {
        return new PaymentMethodResource($this->find($request, $method));
    }

    /** Make it the default method and/or switch automatic payment on or off for it. */
    public function update(Request $request, string $method): PaymentMethodResource
    {
        $data = $request->validate(['is_default' => ['sometimes', 'accepted'], 'autopay' => ['nullable', 'boolean']]);
        if (! array_key_exists('is_default', $data) && ! array_key_exists('autopay', $data)) {
            throw new \DomainException('Send is_default: true and/or autopay.');
        }
        $model = $this->find($request, $method);
        if (array_key_exists('is_default', $data)) {
            $this->methods->makeDefault($model);
        }
        if (array_key_exists('autopay', $data) && $data['autopay'] !== null) {
            $this->methods->setAutopay($model, $request->boolean('autopay'));
        }

        return new PaymentMethodResource($model->refresh());
    }

    /** Remove at the gateway and locally. gateway_confirmed is false when the provider could not confirm (it is removed here either way). */
    public function destroy(Request $request, string $method)
    {
        $ok = $this->methods->remove($this->find($request, $method));

        return response()->json(['data' => ['removed' => true, 'gateway_confirmed' => $ok]]);
    }

    /** Charge an open invoice to a saved method (ownership of both is checked). */
    public function pay(Request $request, string $invoice)
    {
        $data = $request->validate(['payment_method' => ['required', 'string']]);
        $model = $this->client($request)->invoices()->where('status', '!=', 'draft')->where('uuid', $invoice)->firstOrFail()->load('client');
        $method = $this->find($request, $data['payment_method']);
        if (! $model->isPayable()) {
            throw new \DomainException('This invoice cannot be paid.');
        }
        $result = $this->methods->pay($method, $model);
        if ($result->status === ChargeResult::FAILED) {
            return response()->json(['error' => ['code' => 'payment_failed', 'message' => $result->message ?: 'The payment was declined.']], 422);
        }

        return response()->json(['data' => [
            'status' => $result->status === ChargeResult::SUCCESS ? 'succeeded' : $result->status,
            'message' => $result->status === ChargeResult::REQUIRES_ACTION ? "Your bank needs you to confirm this payment. Pay with the provider's checkout instead (POST /invoices/{uuid}/pay)." : $result->message,
            'invoice' => (new InvoiceResource($model->refresh()->load('items', 'payments')))->resolve($request),
        ]]);
    }

    private function find(Request $request, string $uuid): PaymentMethod
    {
        return PaymentMethod::active()->where('client_id', $this->client($request)->id)->where('uuid', $uuid)->firstOrFail();
    }
}
