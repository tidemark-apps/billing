@props(['title', 'description' => null, 'action' => null, 'method' => 'POST', 'danger' => false, 'files' => false, 'id' => null])
{{-- A Vercel-style settings card: title, description, body, and a footer with the save button. Wrap fields in the slot. --}}
<form class="card setting {{ $danger ? 'danger-zone' : '' }}" @if($id) id="{{ $id }}" @endif method="{{ strtoupper($method) === 'GET' ? 'GET' : 'POST' }}" action="{{ $action }}" @if($files) enctype="multipart/form-data" @endif {{ $attributes }}>
    @if (strtoupper($method) !== 'GET')@csrf @if (! in_array(strtoupper($method), ['GET', 'POST']))@method($method)@endif @endif
    <div class="setting-h"><h2>{{ $title }}</h2>@if($description)<p>{{ $description }}</p>@endif</div>
    <div class="setting-b">{{ $slot }}</div>
    <div class="setting-f">@isset($help)<span class="help">{{ $help }}</span>@else<span></span>@endisset
        <div class="row">@isset($footer){{ $footer }}@else<button class="btn primary sm" type="submit">Save</button>@endisset</div></div>
</form>
