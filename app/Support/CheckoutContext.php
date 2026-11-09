<?php

namespace App\Support;

final class CheckoutContext
{
    public function __construct(
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly string $webhookUrl,
        public readonly ?string $customerEmail = null,
    ) {}
}
