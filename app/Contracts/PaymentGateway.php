<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\RefundResult;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A payment gateway plugin. Register one with $this->registerGateway(). Most gateways should extend
 * App\Plugins\AbstractGateway, which stores field values under the "gateway.{key}." settings prefix.
 *
 * Payments are recorded with App\Services\PaymentService::record(), which is idempotent on (gateway, reference),
 * so it is safe to call from both the browser return and the webhook.
 */
interface PaymentGateway
{
    /** Stable identifier, e.g. "stripe". Used in URLs and on payment records. */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** @return array<int, array<string, mixed>> settings fields shown in Settings → Payments */
    public function fields(): array;

    /** True once the required credentials are saved. The gateway is only offered when configured and enabled. */
    public function isConfigured(): bool;

    public function supportsCurrency(string $currency): bool;

    /** Start paying an invoice. Usually returns a redirect to the provider's hosted page. */
    public function checkout(Invoice $invoice, CheckoutContext $context): CheckoutResult;

    /** The customer came back from the provider. Verify/capture and record the payment if needed. */
    public function complete(Request $request, Invoice $invoice): ?Payment;

    /** Handle a provider webhook at POST /webhooks/gateways/{key}. Verify the signature yourself. */
    public function webhook(Request $request): Response;

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult;
}
