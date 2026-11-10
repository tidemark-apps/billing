@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Profile')
@section('content')
<x-page-head title="Profile" />
<x-setting title="Your details" description="Used on invoices and for contacting you." :action="route('portal.profile.update')">
    <div class="grid2">
        <x-field name="name" label="Name" :value="$user->name" required autocomplete="name" /><x-field name="email" label="Email" type="email" :value="$user->email" required autocomplete="email" />
        <x-field name="company_name" label="Company (optional)" autocomplete="organization" :value="$client->company_name" /><x-field name="phone" label="Phone (optional)" type="tel" autocomplete="tel" :value="$client->phone" />
        <x-field name="address_1" label="Address (optional)" autocomplete="address-line1" :value="$client->address_1" /><x-field name="address_2" label="Address line 2 (optional)" autocomplete="address-line2" :value="$client->address_2" />
        <x-field name="city" label="City (optional)" autocomplete="address-level2" :value="$client->city" /><x-field name="state" label="State / region (optional)" autocomplete="address-level1" :value="$client->state" />
        <x-field name="postcode" label="Postcode (optional)" autocomplete="postal-code" :value="$client->postcode" />
        <x-field name="country" label="Country (optional)" type="select" autocomplete="country" :options="['' => 'Select…'] + \App\Support\Countries::all()" :value="$client->country" />
        <x-field name="tax_id" label="Tax / VAT ID (optional)" :value="$client->tax_id" />
        @if ($canChangeCurrency)<x-field name="currency" label="Currency" type="select" :options="$currencies" :value="$client->currency" help="Locked once your first invoice is issued." />
        @else<div><span class="muted small">Currency</span><div><b>{{ $client->currency }}</b></div></div>@endif
    </div>
</x-setting>
<x-setting title="Password" description="Use a long, unique password." :action="route('portal.profile.password')">
    <div class="grid2"><x-portal-password name="current_password" label="Current password" autocomplete="current-password" /><span></span>
        <x-portal-password name="password" label="New password" autocomplete="new-password" help="At least 8 characters." /><x-portal-password name="password_confirmation" label="Confirm new password" autocomplete="new-password" /></div>
</x-setting>
@endsection
