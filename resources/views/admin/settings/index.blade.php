@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Settings')
@section('content')
<x-page-head title="Settings" sub="Your business details, billing rules, email and payments." />
<nav class="tabs page-tabs" aria-label="Settings sections">
    @foreach ($sections as $key => $label)<a href="{{ route('admin.settings.index', $key) }}" class="{{ $section === $key ? 'on' : '' }}">{{ $label }}</a>@endforeach
    <a href="{{ Route::has('admin.tax-rates.index') ? route('admin.tax-rates.index') : '#' }}">Tax rates</a>
</nav>

@if ($section === 'payments')
    @if (! $gateways)
        <x-card><x-empty icon="credit-card" title="No payment gateways installed" text="Payment providers are plugins. Enable Stripe or PayPal under Plugins to accept online payments. Until then you can record bank transfers manually on each invoice."><a class="btn primary" href="{{ route('admin.plugins.index') }}">Open plugins</a></x-empty></x-card>
    @else
        <form method="POST" action="{{ route('admin.settings.update', 'payments') }}" class="stack">
            @csrf @method('PUT')
            @foreach ($gateways as $key => $gw)
                @php($on = \App\Support\Settings::bool("gateway.$key.enabled"))
                <section class="card setting">
                    <div class="setting-h row spread"><div><h2>{{ $gw->label() }}</h2>@if($gw->description())<p>{{ $gw->description() }}</p>@endif</div>
                        @if ($gw->isConfigured() && $on)<span class="pill good">Live</span>@elseif ($on)<span class="pill warn">Enabled, needs credentials</span>@else<span class="pill">Off</span>@endif</div>
                    <div class="setting-b">
                        <x-field :name="'gateways['.$key.'][enabled]'" label="Accept payments with {{ $gw->label() }}" type="checkbox" :value="$on" />
                        <x-field :name="'gateways['.$key.'][title]'" label="Name shown to customers" :value="\App\Support\Settings::get('gateway.'.$key.'.title')" :placeholder="$gw->label()" />
                        <x-schema-fields :fields="$gw->fields()" :values="\App\Support\Settings::group('gateway.'.$key.'.')" :prefix="'gateways['.$key.']'" />
                        <p class="help">Webhook URL for this gateway: <code>{{ route('webhooks.gateway', $key) }}</code> <button type="button" class="btn sm ghost" data-copy="{{ route('webhooks.gateway', $key) }}">Copy</button></p>
                    </div>
                </section>
            @endforeach
            <div class="row"><button class="btn primary" type="submit">Save payment settings</button></div>
        </form>
    @endif
@elseif ($section === 'system')
    <x-card title="This installation">
        <dl class="kv wide">@foreach ($system as $k => $v)@if (! in_array($k, ['cron_url', 'cli_cron']))<dt>{{ $k }}</dt><dd>{{ $v }}</dd>@endif @endforeach</dl>
    </x-card>
    <x-card title="Scheduled tasks">
        <p class="muted" style="margin-bottom:12px">Renewal invoices, reminders, suspensions and queued jobs run from one cron entry. Pick whichever your host supports.</p>
        <div class="stack sm">
            <b>Command-line cron (best)</b>
            <div class="secret-box"><span class="grow">{{ $system['cli_cron'] }}</span><button type="button" class="btn sm" data-copy="{{ $system['cli_cron'] }}">Copy</button></div>
            <b style="margin-top:8px">Web cron (shared hosting without CLI cron)</b>
            @if ($system['cron_url'])
                <div class="secret-box"><span class="grow">{{ $system['cron_url'] }}</span><button type="button" class="btn sm" data-copy="{{ $system['cron_url'] }}">Copy</button></div>
                <p class="help">Call this URL every minute (cPanel "Cron Jobs" with <code>wget -q -O /dev/null "URL"</code>, or a free monitoring service).</p>
            @else<p class="muted">No web cron URL yet.</p>@endif
            <form method="POST" action="{{ route('admin.settings.cron-token') }}" class="row" data-confirm="This invalidates the current cron URL.">@csrf<button class="btn sm" type="submit">{{ $system['cron_url'] ? 'Regenerate URL' : 'Generate web cron URL' }}</button></form>
        </div>
        <x-slot:footer><span>Run everything now, e.g. to test your setup.</span>
            <form method="POST" action="{{ route('admin.settings.run') }}">@csrf<button class="btn sm" type="submit">Run billing tasks now</button></form></x-slot:footer>
    </x-card>
    @include('admin.settings._pdf-fonts')
@else
    <form method="POST" action="{{ route('admin.settings.update', $section) }}" class="stack">
        @csrf @method('PUT')
        @foreach ($groups as $g)
            <section class="card setting">
                <div class="setting-h"><h2>{{ $g['title'] }}</h2><p>{{ $g['description'] }}</p></div>
                <div class="setting-b">
                    <x-schema-fields :fields="$g['fields']" :values="$values" prefix="s" />
                </div>
            </section>
        @endforeach
        <div class="row"><button class="btn primary" type="submit">Save changes</button>
            @if ($section === 'email')<button class="btn" type="submit" form="email-test">Send test email to me</button>@endif</div>
    </form>
    @if ($section === 'email')<form id="email-test" method="POST" action="{{ route('admin.settings.email.test') }}">@csrf</form>@endif
@endif
@endsection
