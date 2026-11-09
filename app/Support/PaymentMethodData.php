<?php

namespace App\Support;

/** A saved payment method as a gateway reports it after a hosted setup. Never carries a card number. */
final class PaymentMethodData
{
    /**
     * @param  string  $reference  the gateway's token for the method (Stripe pm_…, PayPal vault id)
     * @param  string|null  $customerReference  the gateway-side customer the token belongs to
     * @param  array<string, mixed>  $data  extra gateway data (stored encrypted)
     */
    public function __construct(
        public readonly string $reference,
        public readonly ?string $customerReference = null,
        public readonly ?string $brand = null,
        public readonly ?string $last4 = null,
        public readonly ?int $expMonth = null,
        public readonly ?int $expYear = null,
        public readonly ?string $label = null,
        public readonly array $data = [],
    ) {}
}
