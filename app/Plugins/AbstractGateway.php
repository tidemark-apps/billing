<?php

namespace App\Plugins;

use App\Contracts\PaymentGateway;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\RefundResult;
use App\Support\Settings;
use Illuminate\Http\Request;

abstract class AbstractGateway implements PaymentGateway
{
    public function description(): string
    {
        return '';
    }

    public function isConfigured(): bool
    {
        foreach ($this->fields() as $f) {
            if (! empty($f['required']) && ! $this->setting($f['key'])) {
                return false;
            }
        }

        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    public function complete(Request $request, Invoice $invoice): ?Payment
    {
        return null;
    }

    public function refund(Payment $payment, int $amount, ?string $reason = null): RefundResult
    {
        return RefundResult::fail($this->label().' refunds are not supported from here. Refund in the provider dashboard.');
    }

    /** A gateway setting, stored under "gateway.{key}.{name}". */
    protected function setting(string $name, mixed $default = null): mixed
    {
        return Settings::get('gateway.'.$this->key().'.'.$name, $default);
    }
}
