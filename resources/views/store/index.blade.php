@extends('layouts.public')
@section('title', 'Store')
@section('content')
<x-page-head title="Our services" sub="Pick a plan and order in a minute." />
@include('store._catalogue')
@endsection
