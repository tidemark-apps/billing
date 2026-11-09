@extends('layouts.app', ['area' => 'admin'])
@section('title', ($plugin->manifest['name'] ?? $plugin->slug()).' settings')
@section('content')
<x-page-head :title="($plugin->manifest['name'] ?? $plugin->slug()).' settings'" :back="route('admin.plugins.index')" back-label="Plugins" />
<x-setting :title="$plugin->manifest['name'] ?? $plugin->slug()" :description="$plugin->manifest['description'] ?? null" :action="route('admin.plugins.settings.update', $plugin->slug())" method="PUT">
    <x-schema-fields :fields="$fields" :values="$values" prefix="config" />
</x-setting>
@endsection
