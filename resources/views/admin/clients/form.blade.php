@extends('layouts.app', ['area' => 'admin'])
@php($editing = $client->exists)
@section('title', $editing ? 'Edit '.$client->displayName() : 'New client')
@section('content')
<x-page-head :title="$editing ? 'Edit client' : 'New client'" :back="$editing ? route('admin.clients.show', $client) : route('admin.clients.index')" backLabel="Back" />
<form method="POST" action="{{ $editing ? route('admin.clients.update', $client) : route('admin.clients.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Contact">
        <div class="grid2">
            <x-field name="name" label="Contact name" :value="$client->name" required />
            <x-field name="company_name" label="Company" :value="$client->company_name" />
            <x-field name="email" label="Billing email" type="email" :value="$client->email" required />
            <x-field name="phone" label="Phone" :value="$client->phone" />
        </div>
    </x-card>
    <x-card title="Address">
        <div class="grid2">
            <x-field name="address_1" label="Address line 1" :value="$client->address_1" />
            <x-field name="address_2" label="Address line 2" :value="$client->address_2" />
            <x-field name="city" label="City" :value="$client->city" />
            <x-field name="state" label="State / region" :value="$client->state" />
            <x-field name="postcode" label="Postcode" :value="$client->postcode" />
            <x-field name="country" label="Country" type="select" :options="$countries" :value="$client->country" />
        </div>
    </x-card>
    <x-card title="Billing">
        <div class="grid3">
            <x-field name="currency" label="Currency" type="select" :options="$currencies" :value="$client->currency" help="Prices and invoices use this currency." />
            <x-field name="tax_id" label="Tax / VAT ID" :value="$client->tax_id" />
            <x-field name="status" label="Status" type="select" :options="['active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed']" :value="$client->status" />
        </div>
        <div style="margin-top:16px"><x-field name="is_internal" type="checkbox" label="Internal account" :value="$client->is_internal" help="For your own team, test customers and accounts on the house. Every service they have costs nothing, new ones are free, and they are left out of revenue and other report figures. Turning it off puts the normal prices back." /></div>
        <div style="margin-top:16px"><x-field name="notes" label="Internal notes" type="textarea" :value="$client->notes" help="Only staff can see these." /></div>
    </x-card>
    @unless ($editing)
    <x-card title="Client area login">
        <div class="stack sm">
            <x-field name="create_login" type="checkbox" label="Create a client-area login" help="Lets them sign in to pay invoices and manage services." data-reveal-toggle />
            <div class="grid2">
                <x-field name="password" label="Initial password" type="password" help="At least 8 characters. Required when creating a login." autocomplete="new-password" />
                <div style="align-self:end"><x-field name="send_welcome" type="checkbox" label="Send welcome email" help="Leave off to skip the email." /></div>
            </div>
        </div>
    </x-card>
    @endunless
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Create client' }}</button><a class="btn" href="{{ $editing ? route('admin.clients.show', $client) : route('admin.clients.index') }}">Cancel</a></div>
</form>
@endsection
