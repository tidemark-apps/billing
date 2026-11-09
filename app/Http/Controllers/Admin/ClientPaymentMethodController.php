<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Services\PaymentMethods;
use Illuminate\Http\RedirectResponse;

/** Staff can see a client's saved methods (on the client page) and remove one. They cannot add or charge them. */
class ClientPaymentMethodController extends Controller
{
    public function destroy(PaymentMethods $methods, Client $client, string $method): RedirectResponse
    {
        $m = PaymentMethod::active()->where('client_id', $client->id)->where('uuid', $method)->firstOrFail();
        $ok = $methods->remove($m, 'staff');

        return back()->with($ok ? 'status' : 'error', $ok ? 'Payment method removed.' : 'Removed here, but the provider could not confirm the deletion.');
    }
}
