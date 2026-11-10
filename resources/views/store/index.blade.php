@extends('layouts.public')
@section('title', 'Store')
@section('content')
<div class="store">
@include('store._catalogue', ['public' => true])
</div>
@endsection
