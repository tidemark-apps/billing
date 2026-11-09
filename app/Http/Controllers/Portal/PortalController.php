<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Service;

/** Base for client-area controllers: every lookup is scoped to the signed-in user's client, and anything else is a 404. */
abstract class PortalController extends Controller
{
    protected function client(): Client
    {
        return auth()->user()->client ?? abort(404);
    }

    protected function service(int|string $id): Service
    {
        return Service::where('client_id', $this->client()->id)->findOrFail($id);
    }

    protected function invoice(int|string $id): Invoice
    {
        return Invoice::where('client_id', $this->client()->id)->where('status', '!=', 'draft')->findOrFail($id);
    }

    protected function quote(int|string $id): Quote
    {
        return Quote::where('client_id', $this->client()->id)->where('status', '!=', 'draft')->findOrFail($id);
    }
}
