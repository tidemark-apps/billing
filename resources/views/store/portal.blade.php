@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Order services')
@section('content')
<x-page-head title="Order services" sub="Choose something to add to your account." />
@include('store._catalogue')
@endsection
