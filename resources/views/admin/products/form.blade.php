@extends('layouts.app', ['area' => 'admin'])
@php($editing = $product->exists)
@section('title', $editing ? 'Edit '.$product->name : 'New product')
@section('content')
<x-page-head :title="$editing ? $product->name : 'New product'" :sub="$editing ? null : 'Set what it is, what it costs and how it is provisioned.'" :back="route('admin.products.index')" backLabel="Products" />
<form method="POST" action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}" class="stack form-narrow" data-validate>
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Product">
        <div class="grid2">
            <x-field name="name" aria-required="true" label="Name" :value="$product->name" />
            <x-field name="slug" label="URL slug (optional)" :value="$product->slug" help="Leave blank to generate from the name." />
            <x-field name="product_group_id" label="Group (optional)" type="select" :options="$groups" :value="$product->product_group_id" />
            <x-field name="label_prompt" label="Ask the customer for… (optional)" :value="$product->label_prompt" help="E.g. “Domain name”. Shown at checkout." />
        </div>
        <div class="mt-16"><x-field name="description" label="Description (optional)" type="textarea" :value="$product->description" /></div>
        <div class="stack sm mt-16">
            <x-field name="is_active" type="checkbox" label="Active" help="Can be ordered." :value="$product->is_active" />
            <x-field name="is_visible" type="checkbox" label="Show in store" help="Listed on the storefront. Off hides it but keeps existing services." :value="$product->is_visible" />
            <x-field name="tax_exempt" type="checkbox" label="Tax exempt" :value="$product->tax_exempt" />
        </div>
    </x-card>
    <x-card title="Type">
        <x-field name="kind" label="What is this?" type="select" :options="['plan' => 'A plan (ordered on its own)', 'addon' => 'An add-on (added to an existing service)']" :value="$product->kind ?: 'plan'" data-reveal="kind" help="An add-on is not listed in the store. Customers add it to a service from the client area; it bills on that service's cycle and renewal date, so give it a price for each cycle your plans use." />
        <div class="stack hidden mt-16" data-reveal-group="kind" data-reveal-for="addon">
            <fieldset class="choice-group"><legend>Can be added to</legend>
                @forelse ($plans as $id => $name)
                <label class="choice"><input type="checkbox" name="addon_for[]" value="{{ $id }}" @checked(in_array($id, (array) old('addon_for', $product->addon_for ?? [])))><span><b>{{ $name }}</b></span></label>
                @empty<p class="help">No plans yet.</p>@endforelse
                <p class="help">Tick none to allow every plan.</p>
            </fieldset>
            <fieldset class="choice-group"><legend>What it switches on</legend>
                @foreach (config('billing.grants') as $key => $label)
                <label class="choice"><input type="checkbox" name="grants[]" value="{{ $key }}" @checked(in_array($key, (array) old('grants', $product->grants ?? [])))><span><b>{{ $label }}</b></span></label>
                @endforeach
                <p class="help">Only used by provisioning modules that support it. Leave empty for an add-on that is just a billing line.</p>
            </fieldset>
        </div>
    </x-card>
    <x-card title="Pricing">
        @error('prices')<div class="alert mb-16" role="alert">{{ $message }}</div>@enderror
        <div data-repeater data-next="{{ count($prices) }}" data-min="0">
            <div class="line-row head prices"><span>Billing cycle</span><span>Currency</span><span>Price</span><span>Setup fee (optional)</span><span></span></div>
            <div data-rows>
            @foreach ($prices as $i => $p)
                @include('admin.products.price-row', ['i' => $i, 'p' => $p])
            @endforeach
            </div>
            <template>@include('admin.products.price-row', ['i' => '__INDEX__', 'p' => ['cycle' => 'monthly', 'currency' => $defaultCurrency, 'amount' => '', 'setup_fee' => '']])</template>
            <button type="button" class="btn sm" data-add><x-icon name="plus" :size="14" /> Add price</button>
        </div>
        <p class="help mt-8">Amounts in major units (12.50). Add one row per cycle and currency. Clients see their own currency, else the store currency.</p>
    </x-card>
    <x-card title="Trial and plan changes">
        <div class="grid2">
            <x-field name="trial_days" label="Free trial (days)" type="number" min="0" max="365" :value="$product->trial_days ?: 0" help="0 means no trial. The service starts at once and is first invoiced when the trial ends. One trial per customer per product." />
        </div>
        <div class="stack sm mt-16">
            <x-field name="trial_requires_payment_method" type="checkbox" label="Require a saved payment method to start a trial" :value="$product->trial_requires_payment_method" />
            <x-field name="allow_plan_changes" type="checkbox" label="Customers can change plan" help="Lets customers upgrade or downgrade to other plans in the same group from the client area." :value="$product->allow_plan_changes" />
        </div>
    </x-card>
    @include('admin.products.meters')
    <x-card title="Provisioning">
        <x-field name="module" label="Module" type="select" :options="['' => 'None (manual service)'] + collect($modules)->map->label()->all()" :value="$product->module" data-reveal="module" help="What happens when this product is paid for." />
        @foreach ($modules as $key => $m)
            @if ($m->productFields())
            <div class="stack hidden mt-16" data-reveal-group="module" data-reveal-for="{{ $key }}">
                <x-schema-fields :fields="$m->productFields()" :values="$product->module === $key ? ($product->module_config ?? []) : []" prefix="module_config" />
            </div>@endif
        @endforeach
    </x-card>
    {!! extensions('admin.product', ['product' => $product]) !!}
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Create product' }}</button><a class="btn" href="{{ route('admin.products.index') }}">Cancel</a></div>
</form>
@if ($editing && auth()->user()->isAdmin())
<form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="card setting danger-zone form-narrow mt-16" data-confirm="Delete {{ $product->name }}? It disappears from the store and admin lists." data-confirm-label="Delete product" data-danger>@csrf @method('DELETE')
    <div class="setting-h"><h2>Danger zone</h2><p>A product with live services can't be deleted. Mark it Inactive instead to stop new orders.</p></div>
    <div class="setting-f"><span class="help">Delete {{ $product->name }}</span><button class="btn danger sm" type="submit">Delete product</button></div>
</form>
@endif
@endsection
