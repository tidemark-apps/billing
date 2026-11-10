@extends('layouts.auth')
@section('title', 'Two-factor authentication')
@section('content')
<div><h1>Two-factor check</h1><p class="auth-sub">{{ $recovery ? 'Enter one of your recovery codes. Each works once.' : 'Enter the 6-digit code from your authenticator app.' }}</p></div>
<form method="POST" action="{{ route('two-factor.verify') }}" class="stack">
    @csrf
    @if ($recovery)<input type="hidden" name="recovery" value="1">
        <x-field name="code" label="Recovery code" required autofocus autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="xxxxx-xxxxx" />
    @else
        <x-field name="code" label="Authentication code" required autofocus autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" help="The code changes every 30 seconds." />
    @endif
    <label class="check"><input type="checkbox" name="remember_device" value="1"> Remember this device for 30 days</label>
    <button class="btn primary" type="submit">Verify</button>
</form>
<p class="auth-links">@if ($recovery)<a href="{{ route('two-factor.challenge') }}">Use an authenticator code instead</a>@else<a href="{{ route('two-factor.challenge', ['recovery' => 1]) }}">Use a recovery code</a>@endif · <a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
