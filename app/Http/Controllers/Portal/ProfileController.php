<?php

namespace App\Http\Controllers\Portal;

use App\Support\Countries;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends PortalController
{
    public function edit()
    {
        $client = $this->client();

        return view('portal.profile', ['client' => $client, 'user' => auth()->user(), 'canChangeCurrency' => $client->invoices()->doesntExist(), 'currencies' => Money::currencies()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $client = $this->client();
        $user = $request->user();
        $canCurrency = $client->invoices()->doesntExist();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'company_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address_1' => ['nullable', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', Rule::in(array_keys(Money::currencies()))],
        ]);
        $user->update(['name' => $data['name'], 'email' => $data['email']]);
        $fields = collect($data)->except('currency')->all();
        if ($canCurrency && ! empty($data['currency'])) {
            $fields['currency'] = $data['currency'];
        }
        $client->update($fields + ['email' => $data['email']]);

        return back()->with('status', 'Profile updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('status', 'Password changed.');
    }
}
