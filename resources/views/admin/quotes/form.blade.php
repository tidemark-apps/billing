@extends('layouts.app', ['area' => 'admin'])
@section('title', $quote ? 'Edit '.$quote->displayNumber() : 'New quote')
@section('content')
<x-page-head :title="$quote ? 'Edit '.$quote->displayNumber() : 'New quote'" :back="$quote ? route('admin.quotes.show', $quote) : route('admin.quotes.index')" back-label="{{ $quote ? 'Quote' : 'Quotes' }}" />
<form method="POST" class="stack form-narrow" data-validate action="{{ $quote ? route('admin.quotes.update', $quote) : route('admin.quotes.store') }}">
    @csrf
    @if ($quote)@method('PUT')@endif
    @include('admin.partials.form-errors')
    <x-card title="Who and what">
        <div class="stack">
            <x-field name="client_id" label="Client" type="select" aria-required="true" data-msg="Choose a client." :value="$quote?->client_id ?? $client?->id" :options="['' => 'Select a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->company_name ? $c->company_name.' ('.$c->name.')' : $c->name])->all()" />
            <x-field name="title" label="Title (optional)" :value="$quote?->title" placeholder="e.g. Website hosting and migration" />
            <div class="grid2">
                <x-field name="currency" label="Currency" type="select" aria-required="true" :value="$currency" :options="$currencies" help="Amounts below are in this currency." />
                <x-field name="valid_until" label="Valid until (optional)" type="date" :value="$validUntil" help="After this date the client can no longer accept." />
            </div>
        </div>
    </x-card>
    <x-card>
        @include('admin.partials.line-items', ['quote' => true, 'isNew' => ! $quote])
        <p class="help mt-12">Lines added from the catalogue are ordered as services when the client accepts; other lines are billed as one-off items.</p>
    </x-card>
    <x-card title="Notes and terms">
        <div class="stack">
            <x-field name="notes" label="Notes to the client (optional)" type="textarea" :value="$quote?->notes" />
            <x-field name="terms" label="Terms (optional)" type="textarea" :value="$quote ? $quote->terms : \App\Support\Settings::get('billing.terms')" />
        </div>
    </x-card>
    @include('admin.partials.form-bar', ['cancel' => $quote ? route('admin.quotes.show', $quote) : route('admin.quotes.index'), 'actions' => [['draft', 'Save draft'], ['send', 'Save and send', true]]])
</form>
@endsection
