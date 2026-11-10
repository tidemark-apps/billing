@extends('layouts.app', ['area' => 'admin'])
@php($pname = $plugin->manifest['name'] ?? $plugin->slug())
@section('title', $pname.' settings')
@section('content')
<x-page-head :title="$pname.' settings'" :back="route('admin.plugins.index')" back-label="Plugins" />
<div class="form-col">
@if ($errors->any())<div class="alert" role="alert"><b>{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field needs', $errors->count()) }} attention. Nothing was saved.</b></div>@endif
<form method="POST" action="{{ route('admin.plugins.settings.update', $plugin->slug()) }}" class="card setting" data-dirty-form novalidate>
    @csrf @method('PUT')
    <div class="setting-h"><h2>{{ $pname }}</h2>@if (! empty($plugin->manifest['description']))<p>{{ $plugin->manifest['description'] }}</p>@endif</div>
    <div class="setting-b"><x-system-fields :fields="$fields" :values="$values" prefix="config" /></div>
    <div class="setting-f" data-savebar><span class="help" data-savebar-text>Saved secrets are never shown again. Use Replace to change one.</span>
        <div class="row"><a class="btn sm ghost" href="{{ route('admin.plugins.index') }}">Back</a><button class="btn sm ghost" type="button" data-savebar-discard hidden>Discard</button><button class="btn sm primary" type="submit">Save settings</button></div></div>
</form>
</div>
@endsection
