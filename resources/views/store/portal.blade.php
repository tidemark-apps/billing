@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Order services')
@section('content')
@include('store._catalogue', ['public' => false])
@endsection
