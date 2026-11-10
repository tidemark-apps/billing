@extends('layouts.app', ['area' => 'admin'])
@section('title', $invoice ? 'Edit '.$invoice->displayNumber() : 'New invoice')
@section('content')
<x-page-head :title="$invoice ? 'Edit '.$invoice->displayNumber() : 'New invoice'" :back="$invoice ? route('admin.invoices.show', $invoice) : route('admin.invoices.index')" back-label="{{ $invoice ? 'Invoice' : 'Invoices' }}" />
<form method="POST" class="stack form-narrow" data-validate action="{{ $invoice ? route('admin.invoices.update', $invoice) : route('admin.invoices.store') }}">
    @csrf
    @if ($invoice)@method('PUT')@endif
    @include('admin.partials.form-errors')
    <x-card title="Who and when">
        <div class="stack">
            <x-field name="client_id" label="Client" type="select" aria-required="true" data-msg="Choose a client." :value="$invoice?->client_id ?? $client?->id" :options="['' => 'Select a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->company_name ? $c->company_name.' ('.$c->name.')' : $c->name])->all()" />
            <div class="grid2">
                <x-field name="currency" label="Currency" type="select" aria-required="true" :value="$currency" :options="$currencies" help="Amounts below are in this currency." />
                <x-field name="reference" label="Reference or PO number (optional)" :value="$invoice?->reference" help="Shown on the invoice so the client can match it." />
            </div>
            <div class="grid2">
                <x-field name="issue_date" label="Issue date (optional)" type="date" :value="$invoice?->issue_date?->toDateString()" help="Blank means the day you issue it." />
                <x-field name="due_date" label="Due date (optional)" type="date" :value="$invoice?->due_date?->toDateString()" help="Blank uses your default payment terms." />
            </div>
        </div>
    </x-card>
    <x-card>@include('admin.partials.line-items', ['quote' => false, 'isNew' => ! $invoice])</x-card>
    <x-card title="Notes and terms">
        <div class="stack">
            <x-field name="notes" label="Notes to the client (optional)" type="textarea" :value="$invoice?->notes" />
            <x-field name="terms" label="Terms (optional)" type="textarea" :value="$invoice ? $invoice->terms : $defaultTerms" />
        </div>
    </x-card>
    @include('admin.partials.form-bar', ['cancel' => $invoice ? route('admin.invoices.show', $invoice) : route('admin.invoices.index'), 'actions' => [['draft', 'Save draft'], ['issue', 'Save and issue', true]]])
</form>
@endsection
