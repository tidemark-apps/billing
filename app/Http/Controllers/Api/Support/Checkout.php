<?php

namespace App\Http\Controllers\Api\Support;

use App\Models\Invoice;
use App\Plugins\GatewayRegistry;
use App\Support\CheckoutContext;

/** Starts a gateway checkout for an invoice; the same rules as the web PayController. */
class Checkout
{
    public function __construct(private readonly GatewayRegistry $gateways) {}

    /** @throws \DomainException */
    public function url(Invoice $invoice, string $key): string
    {
        if (! $invoice->isPayable()) {
            throw new \DomainException('This invoice cannot be paid.');
        }
        $gateway = $this->gateways->get($key);
        if (! $gateway || ! $this->gateways->isEnabled($key) || ! $gateway->supportsCurrency($invoice->currency)) {
            throw new \DomainException('That payment method is not available for this invoice.');
        }
        $result = $gateway->checkout($invoice, new CheckoutContext(
            successUrl: route('pay.return', [$invoice->uuid, $key]),
            cancelUrl: route('pay.cancel', $invoice->uuid),
            webhookUrl: route('webhooks.gateway', $key),
            customerEmail: $invoice->client->email,
        ));
        if ($result->failed()) {
            throw new \DomainException($result->error ?: 'The payment provider could not start the payment.');
        }

        return $result->redirectUrl;
    }
}
