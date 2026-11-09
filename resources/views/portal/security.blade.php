@extends('layouts.app', ['area' => 'portal'])
@section('title', 'Security')
@section('content')
<x-page-head title="Security" sub="Protect your login with an authenticator app." />
@include('account.panel')
@endsection
