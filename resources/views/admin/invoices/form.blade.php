@extends('layouts.app', ['area' => 'admin'])
@section('title', $invoice ? 'Edit '.$invoice->displayNumber() : 'New invoice')
@section('content')
<x-page-head :title="$invoice ? 'Edit '.$invoice->displayNumber() : 'New invoice'" :back="$invoice ? route('admin.invoices.show', $invoice) : route('admin.invoices.index')" back-label="{{ $invoice ? 'Invoice' : 'Invoices' }}" />
<form method="POST" class="stack" action="{{ $invoice ? route('admin.invoices.update', $invoice) : route('admin.invoices.store') }}">
    @csrf
    @if ($invoice)@method('PUT')@endif
    <x-card title="Details">
        <div class="grid3">
            <x-field name="client_id" label="Client" type="select" required :value="$invoice?->client_id ?? $client?->id" :options="['' => 'Select a client…'] + $clients->mapWithKeys(fn ($c) => [$c->id => $c->company_name ? $c->company_name.' ('.$c->name.')' : $c->name])->all()" />
            <x-field name="currency" label="Currency" type="select" required :value="$currency" :options="$currencies" />
            <x-field name="reference" label="Reference / PO number" :value="$invoice?->reference" />
            <x-field name="issue_date" label="Issue date" type="date" :value="$invoice?->issue_date?->toDateString()" help="Blank means the day it is issued." />
            <x-field name="due_date" label="Due date" type="date" :value="$invoice?->due_date?->toDateString()" help="Blank uses your default payment terms." />
        </div>
    </x-card>
    <x-card>@include('admin.partials.line-items', ['quote' => false, 'isNew' => ! $invoice])</x-card>
    <x-card title="Notes and terms">
        <div class="grid2">
            <x-field name="notes" label="Notes to the client" type="textarea" :value="$invoice?->notes" />
            <x-field name="terms" label="Terms" type="textarea" :value="$invoice ? $invoice->terms : $defaultTerms" />
        </div>
    </x-card>
    <div class="row"><button class="btn" type="submit" name="action" value="draft">Save draft</button><button class="btn primary" type="submit" name="action" value="issue">Save &amp; issue</button><a class="btn ghost" href="{{ $invoice ? route('admin.invoices.show', $invoice) : route('admin.invoices.index') }}">Cancel</a></div>
</form>
@endsection
