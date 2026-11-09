@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Profile')
@section('content')
<x-page-head title="Profile" />
<x-setting title="Your details" description="Used on invoices and for contacting you." :action="route('portal.profile.update')">
    <div class="grid2">
        <x-field name="name" label="Name" :value="$user->name" required /><x-field name="email" label="Email" type="email" :value="$user->email" required />
        <x-field name="company_name" label="Company" :value="$client->company_name" /><x-field name="phone" label="Phone" :value="$client->phone" />
        <x-field name="address_1" label="Address" :value="$client->address_1" /><x-field name="address_2" label="Address line 2" :value="$client->address_2" />
        <x-field name="city" label="City" :value="$client->city" /><x-field name="state" label="State / region" :value="$client->state" />
        <x-field name="postcode" label="Postcode" :value="$client->postcode" />
        <x-field name="country" label="Country" type="select" :options="['' => 'Select…'] + \App\Support\Countries::all()" :value="$client->country" />
        <x-field name="tax_id" label="Tax / VAT ID" :value="$client->tax_id" />
        @if ($canChangeCurrency)<x-field name="currency" label="Currency" type="select" :options="$currencies" :value="$client->currency" help="Locked once your first invoice is issued." />
        @else<div><span class="muted small">Currency</span><div><b>{{ $client->currency }}</b></div></div>@endif
    </div>
</x-setting>
<x-setting title="Password" :action="route('portal.profile.password')">
    <div class="grid2"><x-field name="current_password" label="Current password" type="password" required autocomplete="current-password" /><span></span>
        <x-field name="password" label="New password" type="password" required autocomplete="new-password" /><x-field name="password_confirmation" label="Confirm" type="password" required autocomplete="new-password" /></div>
</x-setting>
@endsection
