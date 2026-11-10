@extends('layouts.app', ['area' => 'admin'])
@php($editing = $client->exists)
@section('title', $editing ? 'Edit '.$client->displayName() : 'New client')
@section('content')
<x-page-head :title="$editing ? 'Edit client' : 'New client'" :back="$editing ? route('admin.clients.show', $client) : route('admin.clients.index')" backLabel="Back" />
<form method="POST" action="{{ $editing ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="stack form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Contact">
        <div class="grid2">
            <x-field name="name" aria-required="true" label="Contact name" :value="$client->name" autocomplete="name" />
            <x-field name="company_name" label="Company (optional)" :value="$client->company_name" />
            <x-field name="email" aria-required="true" label="Billing email" type="email" :value="$client->email" help="Invoices and receipts are sent here." autocomplete="email" />
            <x-field name="phone" label="Phone (optional)" :value="$client->phone" />
        </div>
    </x-card>
    <x-card title="Address">
        <div class="grid2">
            <x-field name="address_1" label="Address line 1 (optional)" :value="$client->address_1" />
            <x-field name="address_2" label="Address line 2 (optional)" :value="$client->address_2" />
            <x-field name="city" label="City (optional)" :value="$client->city" />
            <x-field name="state" label="State / region (optional)" :value="$client->state" />
            <x-field name="postcode" label="Postcode (optional)" :value="$client->postcode" />
            <x-field name="country" label="Country (optional)" type="select" :options="$countries" :value="$client->country" />
        </div>
    </x-card>
    <x-card title="Billing">
        <div class="grid2">
            <x-field name="currency" label="Currency" type="select" :options="$currencies" :value="$client->currency" help="Prices and invoices use this currency." />
            <x-field name="tax_id" label="Tax / VAT ID (optional)" :value="$client->tax_id" />
            <x-field name="status" label="Status" type="select" :options="['active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed']" :value="$client->status" />
        </div>
        <div class="mt-16"><x-field name="is_internal" type="checkbox" label="Internal account" :value="$client->is_internal" help="For your own team, test customers and accounts on the house. Every service they have costs nothing, new ones are free, and they are left out of revenue and other report figures. Turning it off puts the normal prices back." /></div>
        <div class="mt-16"><x-field name="notes" label="Internal notes (optional)" type="textarea" :value="$client->notes" help="Only staff can see these." /></div>
    </x-card>
    @unless ($editing)
    <x-card title="Client area login">
        <div class="stack sm">
            <x-field name="create_login" type="checkbox" label="Create a client-area login" help="Lets them sign in to pay invoices and manage services." data-reveal-toggle />
            <div class="stack">
                <x-field name="password" label="Initial password" type="password" help="At least 8 characters. Needed only when creating a login." autocomplete="new-password" />
                <div><x-field name="send_welcome" type="checkbox" label="Send welcome email" help="Leave off to skip the email." /></div>
            </div>
        </div>
    </x-card>
    @endunless
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Create client' }}</button><a class="btn" href="{{ $editing ? route('admin.clients.show', $client) : route('admin.clients.index') }}">Cancel</a></div>
</form>
@endsection
