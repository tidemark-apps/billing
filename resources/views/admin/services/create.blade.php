@extends('layouts.app', ['area' => 'admin'])
@section('title', 'New service')
@section('content')
<x-page-head title="New service" sub="Adds a service by hand. No invoice is created." :back="route('admin.services.index')" backLabel="Services" />
<form method="POST" action="{{ route('admin.services.store') }}" class="stack">@csrf
    <x-card title="Service">
        <div class="grid2">
            <x-field name="client_id" label="Client" type="select" :options="['' => 'Select a client…'] + $clients" :value="$selectedClient" required />
            <x-field name="product_id" label="Product" type="select" :options="['' => 'Select a product…'] + $products" required />
            <x-field name="billing_cycle" label="Billing cycle" type="select" :options="$cycles" value="monthly" required />
            <x-field name="amount" label="Recurring price" type="number" step="0.01" min="0" help="Leave blank to use the product's price for this cycle." />
            <x-field name="label" label="Label" placeholder="e.g. example.com" />
            <x-field name="next_due_date" label="Next due date" type="date" help="Blank: set automatically on activation." />
        </div>
        <div style="margin-top:16px"><x-field name="activate" type="checkbox" label="Activate now" help="Runs the product's provisioning module immediately. Otherwise the service stays pending." /></div>
    </x-card>
    <div class="row"><button class="btn primary" type="submit">Create service</button><a class="btn" href="{{ route('admin.services.index') }}">Cancel</a></div>
</form>
@endsection
