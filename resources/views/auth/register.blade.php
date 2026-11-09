@extends('layouts.auth')
@section('title', 'Create account')
@section('content')
<div><h1>Create your account</h1><p class="auth-sub">Order services and manage billing in one place.</p></div>
<form method="POST" action="{{ route('register') }}" class="stack">
    @csrf
    <x-field name="name" label="Your name" required autofocus autocomplete="name" />
    <x-field name="company_name" label="Company (optional)" autocomplete="organization" />
    <x-field name="email" label="Email" type="email" required autocomplete="username" />
    <x-field name="country" label="Country" type="select" :options="['' => 'Select…'] + \App\Support\Countries::all()" />
    <x-field name="password" label="Password" type="password" required autocomplete="new-password" help="At least 8 characters." />
    <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
    <button class="btn primary" type="submit">Create account</button>
</form>
<p class="auth-links">Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
