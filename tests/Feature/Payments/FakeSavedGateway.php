<?php

namespace Tests\Feature\Payments;

use App\Contracts\SupportsSavedMethods;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Plugins\AbstractGateway;
use App\Support\ChargeResult;
use App\Support\CheckoutContext;
use App\Support\CheckoutResult;
use App\Support\PaymentMethodData;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A gateway that stores nothing and answers charges from a queue the test controls. */
class FakeSavedGateway extends AbstractGateway implements SupportsSavedMethods
{
    /** @var array<int, ChargeResult> */
    public static array $results = [];

    /** @var array<int, string> */
    public static array $keys = [];

    public static bool $deleteOk = true;

    public function key(): string
    {
        return 'fakecard';
    }

    public function label(): string
    {
        return 'Fake card';
    }

    public function fields(): array
    {
        return [];
    }

    public function supportsCurrency(string $currency): bool
    {
        return $currency !== 'JPY';
    }

    public function checkout(Invoice $invoice, CheckoutContext $context): CheckoutResult
    {
        return CheckoutResult::redirect('https://pay.test/'.$invoice->uuid);
    }

    public function webhook(Request $request): Response
    {
        return response('ok');
    }

    public function beginSetup(Client $client, CheckoutContext $context): CheckoutResult
    {
        return CheckoutResult::redirect('https://setup.test/start?return='.urlencode($context->successUrl));
    }

    public function completeSetup(Request $request, Client $client): ?PaymentMethodData
    {
        return $request->query('ok') ? new PaymentMethodData($request->query('ref', 'tok_1'), 'cus_1', 'visa', '4242', 12, 2099) : null;
    }

    public function charge(PaymentMethod $method, Invoice $invoice, ?string $idempotencyKey = null): ChargeResult
    {
        self::$keys[] = (string) $idempotencyKey;

        return array_shift(self::$results) ?? ChargeResult::success('ch_'.count(self::$keys));
    }

    public function delete(PaymentMethod $method): bool
    {
        return self::$deleteOk;
    }
}
