<?php

namespace App\Contracts;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\PaymentMethodData;
use Illuminate\Http\Request;

/**
 * Optional interface for a PaymentGateway that can store a customer's payment method at the provider (a token,
 * never a card number) and charge it later without the customer present. Gateways that implement it appear on
 * the portal's "Payment methods" page and are used by App\Services\AutoPayer for automatic payment.
 *
 * Flow: beginSetup() redirects to the provider's hosted setup page; the customer returns to
 * $context->successUrl and completeSetup() turns that into PaymentMethodData, which the core stores.
 * Providers can also tell you about changes through the normal webhook(); use App\Services\PaymentMethods
 * (store/markRemoved/updateDetails) there.
 */
interface SupportsSavedMethods
{
    /** Start saving a method for $client. $context->successUrl / cancelUrl are the portal return URLs. */
    public function beginSetup(Client $client, CheckoutContext $context): CheckoutResult;

    /** The customer came back from the hosted setup page. Return the saved method, or null if it was not completed. */
    public function completeSetup(Request $request, Client $client): ?PaymentMethodData;

    /**
     * Charge the invoice's balance to the saved method with no customer present. Must not throw for declines:
     * return ChargeResult::failed() / requiresAction() / pending(). $idempotencyKey must be sent to the provider
     * so a retried call can never charge twice. Do not record the payment here; the caller records it through
     * PaymentService (idempotent on gateway + reference).
     */
    public function charge(PaymentMethod $method, Invoice $invoice, ?string $idempotencyKey = null): ChargeResult;

    /** Remove the token at the provider. Return true when it is gone (or already gone). */
    public function delete(PaymentMethod $method): bool;
}
