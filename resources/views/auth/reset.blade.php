@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('content')
<div><h1>Choose a new password</h1><p class="auth-sub">Pick something you have not used elsewhere.</p></div>
<form method="POST" action="{{ route('password.update') }}" class="stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <x-field name="email" label="Email" type="email" :value="$email" required autocomplete="username" readonly />
    <x-portal-password name="password" label="New password" autocomplete="new-password" help="At least 8 characters." autofocus />
    <x-portal-password name="password_confirmation" label="Confirm new password" autocomplete="new-password" />
    <button class="btn primary" type="submit">Reset password</button>
</form>
@endsection
