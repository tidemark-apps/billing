@props(['title' => null, 'flush' => false, 'id' => null])
<section class="card" @if($id) id="{{ $id }}" @endif>
    @if ($title || isset($actions))
        <div class="card-h"><h3>{{ $title }}</h3>@isset($actions)<div class="row">{{ $actions }}</div>@endisset</div>
    @endif
    <div class="{{ $flush ? 'card-flush' : 'card-b' }}" @if($flush && ($title || isset($actions))) style="margin-top:12px" @endif>{{ $slot }}</div>
    @isset($footer)<div class="card-f">{{ $footer }}</div>@endisset
</section>
