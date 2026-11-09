<?php

namespace App\Plugins;

use App\Contracts\PaymentGateway;
use App\Support\Settings;

class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    public function register(PaymentGateway|string $gateway): void
    {
        $gateway = is_string($gateway) ? app($gateway) : $gateway;
        $this->gateways[$gateway->key()] = $gateway;
    }

    /** @return array<string, PaymentGateway> */
    public function all(): array
    {
        return $this->gateways;
    }

    public function get(string $key): ?PaymentGateway
    {
        return $this->gateways[$key] ?? null;
    }

    public function isEnabled(string $key): bool
    {
        $g = $this->get($key);

        return $g !== null && Settings::bool("gateway.$key.enabled") && $g->isConfigured();
    }

    /** Gateways a customer can pay an invoice with, in the given currency. @return array<string, PaymentGateway> */
    public function available(?string $currency = null): array
    {
        return array_filter($this->gateways, fn (PaymentGateway $g, string $key) => $this->isEnabled($key) && ($currency === null || $g->supportsCurrency($currency)), ARRAY_FILTER_USE_BOTH);
    }

    /** The name shown to customers (admins can rename a gateway in settings). */
    public function title(string $key): string
    {
        return Settings::get("gateway.$key.title") ?: ($this->get($key)?->label() ?? ucfirst($key));
    }
}
