@extends('layouts.auth')
@section('title', 'Sign in')
@section('content')
<div><h1>Sign in</h1><p class="auth-sub">Welcome back. Pay invoices and manage your services.</p></div>
<form method="POST" action="{{ route('login') }}" class="stack">
    @csrf
    <x-field name="email" label="Email" type="email" required autofocus autocomplete="username" />
    <x-field name="password" label="Password" type="password" required autocomplete="current-password" />
    <div class="row spread"><label class="check"><input type="checkbox" name="remember" value="1"> Remember me</label><a class="small muted" href="{{ route('password.request') }}" style="text-decoration:underline;text-underline-offset:3px">Forgot password?</a></div>
    <button class="btn primary" type="submit">Sign in</button>
</form>
@if (config('billing.registration') && \App\Support\Settings::bool('general.registration', true))
    <p class="auth-links">New here? <a href="{{ route('register') }}">Create an account</a></p>
@endif
@endsection
