<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PaymentMethodResource;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Services\PaymentMethods;

/** Staff can see a client's saved methods and remove one (as on the client page). They cannot add or charge them. */
class ClientPaymentMethodController extends ApiController
{
    public function __construct(private readonly PaymentMethods $methods) {}

    public function index(Client $client)
    {
        return PaymentMethodResource::collection($this->methods->forClient($client));
    }

    public function destroy(Client $client, string $method)
    {
        $model = PaymentMethod::active()->where('client_id', $client->id)->where('uuid', $method)->firstOrFail();
        $ok = $this->methods->remove($model, 'staff');
        $this->audit('client.payment_method_removed', $client, 'Removed '.$model->displayName(), ['payment_method' => $model->uuid]);

        return response()->json(['data' => ['removed' => true, 'gateway_confirmed' => $ok]]);
    }
}
