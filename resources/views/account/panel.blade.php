{{-- Two-factor settings panel shared by the staff and client-area security pages. Needs: $prefix, $user, $enabled, $pending, $recoveryCodes, $remaining, $required. --}}
@if ($recoveryCodes)
    <x-card title="Save your recovery codes">
        <p class="muted" style="margin-bottom:12px">Each code works once if you lose your phone. They are shown <b>only now</b>; store them somewhere safe, such as a password manager.</p>
        <div class="secret-box" style="display:block"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:6px;font-family:var(--font-mono, monospace)">@foreach ($recoveryCodes as $c)<span>{{ $c }}</span>@endforeach</div></div>
        <x-slot:footer><span class="help">Regenerating codes later invalidates these.</span><button type="button" class="btn sm" data-copy="{{ implode("\n", $recoveryCodes) }}">Copy codes</button></x-slot:footer>
    </x-card>
@endif

@if ($enabled)
    <x-card title="Two-factor authentication">
        <div class="row"><span class="pill good">On</span><span class="muted">An authenticator app code is required at sign-in. {{ $remaining }} recovery {{ \Illuminate\Support\Str::plural('code', $remaining) }} left.</span></div>
    </x-card>
    <x-setting title="New recovery codes" description="Generates a fresh set and invalidates the old ones." :action="route($prefix.'.recovery-codes')">
        <div class="grid2"><x-field name="current_password" label="Current password" type="password" required autocomplete="current-password" /><span></span></div>
        <x-slot:footer><button class="btn sm" type="submit">Generate new codes</button></x-slot:footer>
    </x-setting>
    <x-card title="Trusted devices" :flush="true">
        <x-slot:actions>@if ($devices->isNotEmpty())<form method="POST" action="{{ route($prefix.'.devices.destroy-all') }}" data-confirm="Revoke every trusted device? Each one will ask for a code at its next sign-in." data-confirm-label="Revoke all" data-danger>@csrf @method('DELETE')<button class="btn sm danger" type="submit">Revoke all</button></form>@endif</x-slot:actions>
        @if ($devices->isEmpty())
            <p class="muted" style="padding:16px">No devices are remembered. Tick "Remember this device" when you enter a code at sign-in to skip the prompt for {{ \App\Services\TwoFactor::DEVICE_DAYS }} days.</p>
        @else
            <table class="t stack-sm"><thead><tr><th>Device</th><th>IP address</th><th>Added</th><th>Last used</th><th>Expires</th><th></th></tr></thead><tbody>
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
        <x-card title="Turn off"><p class="muted">Two-factor authentication is required for staff on this installation, so it cannot be turned off.</p></x-card>
    @else
        <x-setting title="Turn off two-factor authentication" description="Needs your password and a current code (or a recovery code)." :action="route($prefix.'.disable')" method="DELETE" :danger="true">
            <div class="grid2"><x-field name="current_password" label="Current password" type="password" required autocomplete="current-password" />
                <x-field name="code" label="Authenticator or recovery code" required autocomplete="one-time-code" /></div>
            <x-slot:footer><button class="btn danger sm" type="submit">Turn off</button></x-slot:footer>
        </x-setting>
    @endif
@elseif ($pending)
    <x-setting title="Set up your authenticator app" description="Scan the QR code with an authenticator app (1Password, Authy, Google Authenticator...), then enter the 6-digit code it shows." :action="route($prefix.'.confirm')">
        <div class="row" style="align-items:flex-start;gap:24px;flex-wrap:wrap">
            <div role="img" aria-label="QR code for your authenticator app" style="width:200px;height:200px;background:#fff;padding:6px;border-radius:8px;flex:none">{!! $qr !!}</div>
            <div class="stack sm grow" style="min-width:240px">
                <div><span class="muted small">Can't scan? Enter this key</span>
                    <div class="secret-box"><span class="grow" style="word-break:break-all">{{ trim(chunk_split($secret, 4, ' ')) }}</span><button type="button" class="btn sm" data-copy="{{ $secret }}">Copy</button></div></div>
                <div><span class="muted small">Or use this setup link</span>
                    <div class="secret-box"><span class="grow" style="word-break:break-all">{{ $uri }}</span><button type="button" class="btn sm" data-copy="{{ $uri }}">Copy</button></div></div>
                <x-field name="code" label="6-digit code" required autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" />
            </div>
        </div>
        <x-slot:footer><button class="btn primary sm" type="submit">Confirm and turn on</button><button class="btn ghost sm" type="submit" formaction="{{ route($prefix.'.cancel') }}" formnovalidate>Cancel</button></x-slot:footer>
    </x-setting>
@else
    <x-card title="Two-factor authentication">
        <div class="row spread"><div><span class="pill">Off</span> <span class="muted">Add a second step at sign-in so a stolen password is not enough.</span>@if ($required)<p class="muted small" style="margin-top:6px">Required for staff on this installation.</p>@endif</div>
            <form method="POST" action="{{ route($prefix.'.start') }}">@csrf<button class="btn primary" type="submit">Set up</button></form></div>
    </x-card>
@endif
