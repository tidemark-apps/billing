{{-- Two-factor settings panel shared by the staff and client-area security pages. Needs: $prefix, $user, $enabled, $pending, $recoveryCodes, $remaining, $required. --}}
<div class="form-col">
@if ($recoveryCodes)
    <section class="card setting" role="status">
        <div class="setting-h"><h2>Save your recovery codes</h2><p>Each code works once if you lose your phone. They are shown only now, so store them somewhere safe, such as a password manager.</p></div>
        <div class="setting-b"><div class="secret-box"><div class="codes">@foreach ($recoveryCodes as $c)<span>{{ $c }}</span>@endforeach</div></div></div>
        <div class="setting-f"><span class="help">Making new codes later makes these stop working.</span><button type="button" class="btn sm primary" data-copy="{{ implode("\n", $recoveryCodes) }}"><x-icon name="copy" :size="14" /> Copy codes</button></div>
    </section>
@endif

@if ($enabled)
    <section class="card setting">
        <div class="setting-h"><h2>Two-factor authentication <span class="pill good">On</span></h2><p>An authenticator app code is required at sign-in. {{ $remaining }} recovery {{ \Illuminate\Support\Str::plural('code', $remaining) }} left.</p></div>
        @if ($remaining <= 2)<div class="setting-b"><div class="notice warn"><x-icon name="triangle-alert" :size="16" /><div>You are running low on recovery codes. Make a new set below.</div></div></div>@endif
    </section>
    <form class="card setting" method="POST" action="{{ route($prefix.'.recovery-codes') }}" novalidate>
        @csrf
        <div class="setting-h"><h2>New recovery codes</h2><p>Makes a fresh set and stops the old ones working.</p></div>
        <div class="setting-b"><x-system-field name="current_password" label="Current password" type="password" required autocomplete="current-password" /></div>
        <div class="setting-f"><span class="help">The new codes are shown once.</span><button class="btn sm" type="submit">Make new codes</button></div>
    </form>
    <x-card title="Trusted devices" :flush="true">
        <x-slot:actions>@if ($devices->isNotEmpty())<form method="POST" action="{{ route($prefix.'.devices.destroy-all') }}" data-confirm="Revoke every trusted device? Each one will ask for a code at its next sign-in." data-confirm-label="Revoke all" data-danger>@csrf @method('DELETE')<button class="btn sm danger" type="submit">Revoke all</button></form>@endif</x-slot:actions>
        @if ($devices->isEmpty())
            @php($tip = 'Tick "Remember this device" when you enter a code at sign-in to skip the prompt for '.\App\Services\TwoFactor::DEVICE_DAYS.' days.')
            <x-empty icon="laptop" title="No trusted devices" :text="$tip" />
        @else
            <table class="t stack-sm"><thead><tr><th>Device</th><th>IP address</th><th>Added</th><th>Last used</th><th>Expires</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
                @foreach ($devices as $d)
                <tr><td data-label="Device"><b>{{ $d->label ?: 'Unknown device' }}</b>@if ($d->id === $currentDeviceId) <span class="pill good">This device</span>@endif</td>
                    <td data-label="IP address" class="mono">{{ $d->ip ?? 'Unknown' }}</td>
                    <td data-label="Added">{{ $d->created_at->format('j M Y') }}</td>
                    <td data-label="Last used">{{ $d->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                    <td data-label="Expires">{{ $d->expires_at->format('j M Y') }}</td>
                    <td class="right"><form method="POST" action="{{ route($prefix.'.devices.destroy', $d->id) }}" data-confirm="Revoke this device? It will ask for a code at its next sign-in." data-confirm-label="Revoke" data-danger>@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Revoke</button></form></td></tr>
                @endforeach
            </tbody></table>
        @endif
    </x-card>
    @if ($required)
        <section class="card setting"><div class="setting-h"><h2>Turn off</h2><p>Two-factor authentication is required for staff on this installation, so it cannot be turned off.</p></div></section>
    @else
        <form class="card setting danger-zone" method="POST" action="{{ route($prefix.'.disable') }}" novalidate>
            @csrf @method('DELETE')
            <div class="setting-h"><h2>Turn off two-factor authentication</h2><p>Your account will be protected by your password alone. Needs your password and a current code (or a recovery code).</p></div>
            <div class="setting-b"><x-system-field name="current_password" label="Current password" type="password" required autocomplete="current-password" />
                <x-system-field name="code" label="Authenticator or recovery code" required autocomplete="one-time-code" /></div>
            <div class="setting-f"><span class="help">You can turn it on again at any time.</span><button class="btn danger sm" type="submit">Turn off two-factor</button></div>
        </form>
    @endif
@elseif ($pending)
    <form class="card setting" method="POST" action="{{ route($prefix.'.confirm') }}" novalidate>
        @csrf
        <div class="setting-h"><h2>Set up your authenticator app</h2><p>Scan the QR code with an authenticator app (1Password, Authy, Google Authenticator), then enter the 6-digit code it shows.</p></div>
        <div class="setting-b">
            <div class="qr-box" role="img" aria-label="QR code for your authenticator app">{!! $qr !!}</div>
            <div><span class="muted small">Can't scan? Enter this key</span>
                <div class="secret-box"><span class="grow break-all">{{ trim(chunk_split($secret, 4, ' ')) }}</span><button type="button" class="btn sm" data-copy="{{ $secret }}">Copy</button></div></div>
            <div><span class="muted small">Or use this setup link</span>
                <div class="secret-box"><span class="grow break-all">{{ $uri }}</span><button type="button" class="btn sm" data-copy="{{ $uri }}">Copy</button></div></div>
            <x-system-field name="code" label="6-digit code" required autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" help="It changes every 30 seconds." />
        </div>
        <div class="setting-f"><button class="btn ghost sm" type="submit" formaction="{{ route($prefix.'.cancel') }}" formnovalidate>Cancel setup</button><button class="btn primary sm" type="submit">Confirm and turn on</button></div>
    </form>
@else
    <section class="card setting">
        <div class="setting-h"><h2>Two-factor authentication <span class="pill">Off</span></h2><p>Add a second step at sign-in so a stolen password is not enough.@if ($required) Required for staff on this installation.@endif</p></div>
        <div class="setting-f"><span class="help">Takes about a minute with any authenticator app.</span>
            <form method="POST" action="{{ route($prefix.'.start') }}">@csrf<button class="btn primary sm" type="submit">Set up two-factor</button></form></div>
    </section>
@endif
</div>
