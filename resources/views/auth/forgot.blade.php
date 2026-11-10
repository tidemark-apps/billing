@extends('layouts.auth')
@section('title', 'Reset password')
@section('content')
<div><h1>Reset your password</h1><p class="auth-sub">We'll email you a link to choose a new one.</p></div>
<form method="POST" action="{{ route('password.email') }}" class="stack">
    @csrf
    <x-field name="email" label="Email" type="email" required autofocus autocomplete="username" autocapitalize="none" spellcheck="false" />
    <button class="btn primary" type="submit">Send reset link</button>
</form>
<p class="auth-links"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
