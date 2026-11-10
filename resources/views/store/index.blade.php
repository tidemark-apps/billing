@extends(auth()->check() ? 'layouts.app' : 'layouts.public', ['area' => 'portal']) {{-- signed in: the client-area shell; guests: the plain public one --}}
@section('title', 'Store')
@section('content')
<div class="store">
@include('store._catalogue', ['public' => true])
</div>
@endsection
