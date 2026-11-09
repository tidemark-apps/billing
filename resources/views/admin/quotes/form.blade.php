@extends('layouts.app', ['area' => 'admin'])
@section('title', $quote ? 'Edit '.$quote->displayNumber() : 'New quote')
@section('content')
<x-page-head :title="$quote ? 'Edit '.$quote->displayNumber() : 'New quote'" :back="$quote ? route('admin.quotes.show', $quote) : route('admin.quotes.index')" back-label="{{ $quote ? 'Quote' : 'Quotes' }}" />
<form method="POST" class="stack" action="{{ $quote ? route('admin.quotes.update', $quote) : route('admin.quotes.store') }}">
    @csrf
    @if ($quote)@method('PUT')@endif
    <x-card title="Details">
        <div class="grid3">
            <x-field name="client_id" label="Client" type="select" required :value="$quote?->client_id ?? $client?->id" :options="['' => 'Select a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->company_name ? $c->company_name.' ('.$c->name.')' : $c->name])->all()" />
            <x-field name="currency" label="Currency" type="select" required :value="$currency" :options="$currencies" />
            <x-field name="valid_until" label="Valid until" type="date" :value="$validUntil" />
            <x-field name="title" label="Title" :value="$quote?->title" placeholder="e.g. Website hosting and migration" class="span2" />
        </div>
    </x-card>
    <x-card>
        @include('admin.partials.line-items', ['quote' => true, 'isNew' => ! $quote])
        <p class="help" style="margin-top:12px">Lines added from the catalogue are ordered as services when the client accepts; other lines are billed as one-off items.</p>
    </x-card>
    <x-card title="Notes and terms">
        <div class="grid2">
            <x-field name="notes" label="Notes to the client" type="textarea" :value="$quote?->notes" />
            <x-field name="terms" label="Terms" type="textarea" :value="$quote ? $quote->terms : \App\Support\Settings::get('billing.terms')" />
        </div>
    </x-card>
    <div class="row"><button class="btn" type="submit" name="action" value="draft">Save draft</button><button class="btn primary" type="submit" name="action" value="send">Save &amp; send</button><a class="btn ghost" href="{{ $quote ? route('admin.quotes.show', $quote) : route('admin.quotes.index') }}">Cancel</a></div>
</form>
@endsection
