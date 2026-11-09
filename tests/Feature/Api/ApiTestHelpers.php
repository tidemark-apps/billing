<?php

namespace Tests\Feature\Api;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\InvoiceService;

/** Shared helpers for the admin API tests. */
trait ApiTestHelpers
{
    /** Send the next request with a fresh token for $user, optionally limited to $abilities. */
    private function as(User $user, ?array $abilities = null): static
    {
        [, $plain] = ApiToken::issue($user, 'test', $abilities);
        $this->app['auth']->forgetGuards();

        return $this->withToken($plain);
    }

    private function staffUser(): User
    {
        return User::factory()->staff()->create();
    }

    private function makeService(?Client $client = null, ?Product $product = null, array $attrs = []): Service
    {
        $client ??= Client::factory()->create();
        $product ??= Product::factory()->priced(1000)->create();

        return Service::create($attrs + [
            'client_id' => $client->id, 'product_id' => $product->id, 'status' => ServiceStatus::Active, 'billing_cycle' => BillingCycle::Monthly,
            'currency' => 'USD', 'amount' => 1000,
        ]);
    }

    /** An invoice for the client: open (issued) by default, or a draft. */
    private function makeInvoice(?Client $client = null, bool $issue = true, int $price = 1000): Invoice
    {
        return app(InvoiceService::class)->create($client ?? Client::factory()->create(), [['description' => 'Work', 'unit_price' => $price, 'taxable' => false]], ['issue' => $issue, 'send' => false]);
    }
}
