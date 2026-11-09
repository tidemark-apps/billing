<?php

namespace Plugins\LicenseServer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Plugins\LicenseServer\LicenseManager;
use Plugins\LicenseServer\Models\Activation;
use Plugins\LicenseServer\Models\License;
use Plugins\LicenseServer\Signer;

class AdminController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('status');
        $licenses = License::with('service.client', 'service.product')->withCount('activations')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('key', 'like', '%'.$q.'%')->orWhere('product_code', 'like', '%'.$q.'%')
                    ->orWhereHas('service.client', fn ($c) => $c->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')->orWhere('company_name', 'like', '%'.$q.'%'));
            }))
            ->when(in_array($status, ['active', 'suspended', 'revoked'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('license-server::admin.index', ['licenses' => $licenses, 'q' => $q, 'status' => $status, 'publicKey' => Signer::publicKey()]);
    }

    public function show(License $license)
    {
        $license->load('service.client', 'service.product', 'activations');

        return view('license-server::admin.show', ['license' => $license]);
    }

    public function update(Request $request, License $license): RedirectResponse
    {
        $data = $request->validate([
            'max_activations' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'expires_on' => ['nullable', 'date'],
            'extend_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'clear_expiry' => ['nullable', 'boolean'],
        ]);
        if (isset($data['max_activations'])) {
            $license->max_activations = $data['max_activations'];
        }
        if (! empty($data['clear_expiry'])) {
            $license->expires_at = null;
        } elseif (! empty($data['extend_days'])) {
            $license->expires_at = ($license->expires_at && $license->expires_at->isFuture() ? $license->expires_at : now())->copy()->addDays((int) $data['extend_days']);
        } elseif (! empty($data['expires_on'])) {
            $license->expires_at = Carbon::parse($data['expires_on'])->endOfDay();
        }
        $license->save();
        Activity::log('license.updated', $license, 'Licence '.$license->key.' updated');

        return back()->with('status', 'Licence updated.');
    }

    public function revoke(License $license, LicenseManager $manager): RedirectResponse
    {
        $manager->revoke($license, 'revoked by '.auth()->user()->name);

        return back()->with('status', 'Licence revoked.');
    }

    public function reactivate(License $license): RedirectResponse
    {
        $license->update(['status' => 'active', 'suspended_at' => null]);
        Activity::log('license.reactivated', $license, 'Licence '.$license->key.' reactivated');

        return back()->with('status', 'Licence reactivated.');
    }

    public function removeActivation(License $license, Activation $activation): RedirectResponse
    {
        abort_unless($activation->license_id === $license->id, 404);
        $activation->delete();

        return back()->with('status', 'Activation removed.');
    }
}
