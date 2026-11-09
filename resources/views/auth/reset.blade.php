@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('content')
<div><h1>Choose a new password</h1></div>
<form method="POST" action="{{ route('password.update') }}" class="stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <x-field name="email" label="Email" type="email" :value="$email" required />
    <x-field name="password" label="New password" type="password" required autocomplete="new-password" />
    <x-field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />
    <button class="btn primary" type="submit">Reset password</button>
</form>
@endsection
