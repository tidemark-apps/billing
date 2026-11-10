@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', $product->name)
@section('content')
<div class="store">
<x-empty icon="package" title="{{ $product->name }} can't be ordered online right now" text="It has no price set for your currency. Contact us and we will set it up for you."><a class="btn" href="{{ route('store.index') }}">See other services</a></x-empty>
</div>
@endsection
