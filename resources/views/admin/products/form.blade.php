@extends('layouts.app', ['area' => 'admin'])
@php($editing = $product->exists)
@section('title', $editing ? 'Edit '.$product->name : 'New product')
@section('content')
<x-page-head :title="$editing ? $product->name : 'New product'" :back="route('admin.products.index')" backLabel="Products">
    @if ($editing && auth()->user()->isAdmin())<form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm="Delete {{ $product->name }}?" data-confirm-label="Delete" data-danger>@csrf @method('DELETE')<button class="btn danger" type="submit">Delete</button></form>@endif
</x-page-head>
<form method="POST" action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}" class="stack">
    @csrf @if ($editing) @method('PUT') @endif
    <x-card title="Product">
        <div class="grid2">
            <x-field name="name" label="Name" :value="$product->name" required />
            <x-field name="slug" label="URL slug" :value="$product->slug" help="Leave blank to generate from the name." />
            <x-field name="product_group_id" label="Group" type="select" :options="$groups" :value="$product->product_group_id" />
            <x-field name="label_prompt" label="Ask the customer for…" :value="$product->label_prompt" help="E.g. “Domain name”. Shown at checkout." />
        </div>
        <div style="margin-top:16px"><x-field name="description" label="Description" type="textarea" :value="$product->description" /></div>
        <div class="grid3" style="margin-top:16px">
            <x-field name="is_active" type="checkbox" label="Active" help="Can be ordered." :value="$product->is_active" />
            <x-field name="is_visible" type="checkbox" label="Show in store" help="Listed on the storefront." :value="$product->is_visible" />
            <x-field name="tax_exempt" type="checkbox" label="Tax exempt" :value="$product->tax_exempt" />
        </div>
    </x-card>
    <x-card title="Pricing">
        @error('prices')<div class="alert" style="margin-bottom:12px">{{ $message }}</div>@enderror
        <div data-repeater data-next="{{ count($prices) }}" data-min="0">
            <div class="line-row head" style="grid-template-columns:1fr 100px 1fr 1fr 36px"><span>Cycle</span><span>Currency</span><span>Price</span><span>Setup fee</span><span></span></div>
            <div data-rows>
            @foreach ($prices as $i => $p)
                @include('admin.products.price-row', ['i' => $i, 'p' => $p])
            @endforeach
            </div>
            <template>@include('admin.products.price-row', ['i' => '__INDEX__', 'p' => ['cycle' => 'monthly', 'currency' => $defaultCurrency, 'amount' => '', 'setup_fee' => '']])</template>
            <button type="button" class="btn sm" data-add><x-icon name="plus" :size="14" /> Add price</button>
        </div>
        <p class="help" style="margin-top:8px">Amounts in major units (12.50). Add a row per cycle and currency; a client sees prices in their own currency, falling back to the store currency.</p>
    </x-card>
    <x-card title="Trial and plan changes">
        <div class="grid2">
            <x-field name="trial_days" label="Free trial (days)" type="number" min="0" max="365" :value="$product->trial_days ?: 0" help="0 means no trial. Recurring orders start the service at once and are first invoiced when the trial ends. One trial per customer per product." />
        </div>
        <div class="grid2" style="margin-top:16px">
            <x-field name="trial_requires_payment_method" type="checkbox" label="Require a saved payment method to start a trial" :value="$product->trial_requires_payment_method" />
            <x-field name="allow_plan_changes" type="checkbox" label="Customers can change plan" help="Lets customers upgrade or downgrade to other plans in the same group from the client area." :value="$product->allow_plan_changes" />
        </div>
    </x-card>
    @include('admin.products.meters')
    <x-card title="Provisioning">
        <x-field name="module" label="Module" type="select" :options="['' => 'None (manual service)'] + collect($modules)->map->label()->all()" :value="$product->module" data-reveal="module" help="What happens when this product is paid for." />
        @foreach ($modules as $key => $m)
            @if ($m->productFields())
            <div class="stack hidden" style="margin-top:16px" data-reveal-group="module" data-reveal-for="{{ $key }}">
                <x-schema-fields :fields="$m->productFields()" :values="$product->module === $key ? ($product->module_config ?? []) : []" prefix="module_config" />
            </div>@endif
        @endforeach
    </x-card>
    {!! extensions('admin.product', ['product' => $product]) !!}
    <div class="row"><button class="btn primary" type="submit">{{ $editing ? 'Save changes' : 'Create product' }}</button><a class="btn" href="{{ route('admin.products.index') }}">Cancel</a></div>
</form>
@endsection
