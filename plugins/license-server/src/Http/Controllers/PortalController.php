<?php

namespace Plugins\LicenseServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Plugins\LicenseServer\Models\Activation;

class PortalController extends Controller
{
    /** Customers may remove only activations of licences belonging to their own client; anything else is a 404. */
    public function removeActivation(int $activation): RedirectResponse
    {
        $clientId = auth()->user()->client_id;
        $record = Activation::whereKey($activation)
            ->whereHas('license.service', fn ($q) => $q->where('client_id', $clientId))
            ->firstOrFail();
        $record->delete();

        return back()->with('status', 'Activation removed. You can activate it again on another site.');
    }
}
