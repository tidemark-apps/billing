@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Security')
@section('content')
<x-page-head title="Security" sub="Protect your staff login with an authenticator app." />
@include('account.panel')
@endsection
