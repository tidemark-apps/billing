<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Contracts\SupportsSavedMethods;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Plugins\GatewayRegistry;
use App\Support\Activity;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\PaymentMethodData;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Saved payment methods: storing the tokens gateways return, keeping the default straight, and charging them. */
class PaymentMethods
{
    public function __construct(private readonly GatewayRegistry $gateways, private readonly PaymentService $payments) {}

    /** Gateways that are enabled, configured and can save methods. @return array<string, SupportsSavedMethods&\App\Contracts\PaymentGateway> */
    public function gateways(?string $currency = null): array
    {
        return array_filter($this->gateways->available($currency), fn ($g) => $g instanceof SupportsSavedMethods);
    }

    public function gateway(string $key): (SupportsSavedMethods&PaymentGateway)|null
    {
        $g = $this->gateways->get($key);

        return $g instanceof SupportsSavedMethods ? $g : null;
    }

    /** @return Collection<int, PaymentMethod> */
    public function forClient(Client $client)
    {
        return PaymentMethod::active()->where('client_id', $client->id)->orderByDesc('is_default')->orderBy('id')->get();
    }

    public function beginSetup(Client $client, string $gatewayKey, CheckoutContext $context): CheckoutResult
    {
        $gateway = $this->gateway($gatewayKey);
        if (! $gateway || ! $this->gateways->isEnabled($gatewayKey)) {
            return CheckoutResult::error('That payment provider is not available.');
        }

        try {
            return $gateway->beginSetup($client, $context);
        } catch (\Throwable $e) {
            report($e);

            return CheckoutResult::error('The payment provider could not start the setup.');
        }
    }

    public function customerReference(Client $client, string $gateway): ?string
    {
        return DB::table('gateway_customers')->where(['client_id' => $client->id, 'gateway' => $gateway])->value('reference');
    }

    public function rememberCustomer(Client $client, string $gateway, string $reference): void
    {
        DB::table('gateway_customers')->updateOrInsert(['client_id' => $client->id, 'gateway' => $gateway], ['reference' => $reference, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Save (or refresh) a method a gateway reported. The first active method becomes the default. */
    public function store(Client $client, string $gateway, PaymentMethodData $data, bool $autopay = false): PaymentMethod
    {
        return DB::transaction(function () use ($client, $gateway, $data, $autopay) {
            $existing = PaymentMethod::where(['gateway' => $gateway, 'reference' => $data->reference])->first();
            if ($existing && $existing->client_id !== $client->id) {
                throw new \DomainException('This payment method belongs to another account.');
            }
            $fresh = ! $existing || $existing->isRemoved();
            $method = $existing ?? new PaymentMethod(['client_id' => $client->id, 'gateway' => $gateway, 'reference' => $data->reference]);
            $method->fill([
                'customer_reference' => $data->customerReference ?? $method->customer_reference,
                'brand' => $data->brand ? strtolower($data->brand) : $method->brand,
                'last4' => $data->last4 ?? $method->last4,
                'exp_month' => $data->expMonth ?? $method->exp_month,
                'exp_year' => $data->expYear ?? $method->exp_year,
                'label' => $data->label ?? $method->label,
                'data' => $data->data ?: $method->data,
                'removed_at' => null,
            ]);
            if ($fresh) {
                $method->autopay = $autopay;
                $method->is_default = false;
            }
            $method->save();
            if ($data->customerReference) {
                $this->rememberCustomer($client, $gateway, $data->customerReference);
            }
            if (! PaymentMethod::active()->where('client_id', $client->id)->where('is_default', true)->exists()) {
                $method->update(['is_default' => true]);
            }
            if ($fresh) {
                Activity::log('payment_method.added', $method, 'Saved '.$method->displayName().' ('.$gateway.')', ['client_id' => $client->id, 'autopay' => $autopay]);
            }

            return $method->refresh();
        });
    }

    /** Webhook: the gateway refreshed the card (new expiry after reissue, network update). */
    public function updateDetails(string $gateway, string $reference, PaymentMethodData $data): void
    {
        $method = PaymentMethod::active()->where(['gateway' => $gateway, 'reference' => $reference])->first();
        $method?->update(array_filter([
            'brand' => $data->brand ? strtolower($data->brand) : null,
            'last4' => $data->last4,
            'exp_month' => $data->expMonth,
            'exp_year' => $data->expYear,
        ], fn ($v) => $v !== null));
    }

    /** Webhook: the token no longer exists at the gateway. */
    public function markRemoved(string $gateway, string $reference): void
    {
        $method = PaymentMethod::active()->where(['gateway' => $gateway, 'reference' => $reference])->first();
        if ($method) {
            $this->retire($method);
            Activity::log('payment_method.removed', $method, 'Removed at '.$gateway, ['client_id' => $method->client_id, 'source' => 'webhook']);
        }
    }

    /** Remove at the gateway and locally. Returns false when the gateway could not confirm (it is still removed here). */
    public function remove(PaymentMethod $method, string $by = 'customer'): bool
    {
        $ok = true;
        $gateway = $this->gateway($method->gateway);
        if ($gateway) {
            try {
                $ok = $gateway->delete($method);
            } catch (\Throwable $e) {
                report($e);
                $ok = false;
            }
        }
        $this->retire($method);
        Activity::log('payment_method.removed', $method, 'Removed '.$method->displayName().' by '.$by, ['client_id' => $method->client_id, 'gateway_confirmed' => $ok]);

        return $ok;
    }

    private function retire(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            $method->update(['removed_at' => now(), 'is_default' => false, 'autopay' => false]);
            $next = PaymentMethod::active()->where('client_id', $method->client_id)->orderBy('id')->first();
            if ($next && ! PaymentMethod::active()->where('client_id', $method->client_id)->where('is_default', true)->exists()) {
                $next->update(['is_default' => true]);
            }
        });
    }

    public function makeDefault(PaymentMethod $method): void
    {
        DB::transaction(function () use ($method) {
            PaymentMethod::where('client_id', $method->client_id)->update(['is_default' => false]);
            $method->update(['is_default' => true]);
        });
    }

    public function setAutopay(PaymentMethod $method, bool $on): void
    {
        $method->update(['autopay' => $on]);
        Activity::log($on ? 'payment_method.autopay_on' : 'payment_method.autopay_off', $method, 'Automatic payment '.($on ? 'enabled' : 'disabled').' for '.$method->displayName(), ['client_id' => $method->client_id]);
    }

    /** True when this method can pay this invoice right now (gateway on, currency supported, not expired). */
    public function usable(PaymentMethod $method, Invoice $invoice): bool
    {
        $gateway = $this->gateway($method->gateway);

        return $gateway !== null
            && ! $method->isRemoved()
            && ! $method->isExpired()
            && $this->gateways->isEnabled($method->gateway)
            && $gateway->supportsCurrency($invoice->currency);
    }

    /** Active methods of the invoice's client that can pay it, default first. @return \Illuminate\Support\Collection<int, PaymentMethod> */
    public function usableFor(Invoice $invoice)
    {
        return $this->forClient($invoice->client)->filter(fn ($m) => $this->usable($m, $invoice))->values();
    }

    /**
     * Charge the invoice's balance to a saved method and record the payment on success. Idempotent on the gateway
     * reference (PaymentService), and the idempotency key is passed to the gateway so a retry cannot double-charge.
     */
    public function pay(PaymentMethod $method, Invoice $invoice, ?string $idempotencyKey = null): ChargeResult
    {
        $invoice->refresh();
        if (! $invoice->isPayable()) {
            return ChargeResult::failed('This invoice cannot be paid.');
        }
        if (! $this->usable($method, $invoice)) {
            return ChargeResult::failed('This payment method cannot be used for this invoice.');
        }
        $amount = $invoice->balance();
        try {
            $result = $this->gateway($method->gateway)->charge($method, $invoice, $idempotencyKey ?? 'inv-'.$invoice->uuid.'-'.$amount.'-'.now()->timestamp);
        } catch (\Throwable $e) {
            report($e);

            return ChargeResult::failed('The payment provider could not be reached.');
        }
        if ($result->succeeded()) {
            try {
                $this->payments->record($invoice, $method->gateway, $result->amount ?? $amount, $result->reference, [
                    'fee' => $result->fee,
                    'meta' => $result->meta + ['payment_method' => $method->uuid],
                ]);
            } catch (\DomainException $e) {
                report($e);
            }
            $method->update(['last_used_at' => now()]);
        }

        return $result;
    }
}
