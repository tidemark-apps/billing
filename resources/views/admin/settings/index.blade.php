@extends('layouts.app', ['area' => 'admin'])
@section('title', 'Settings')
@section('content')
<x-page-head title="Settings" sub="Your business details, billing rules, email and payments." />
<nav class="tabs page-tabs mb-16" aria-label="Settings sections">
    @foreach ($sections as $key => $label)<a href="{{ route('admin.settings.index', $key) }}" class="{{ $section === $key ? 'on' : '' }}" @if ($section === $key) aria-current="page" @endif>{{ $label }}</a>@endforeach
    <a href="{{ Route::has('admin.tax-rates.index') ? route('admin.tax-rates.index') : '#' }}">Tax rates</a>
</nav>

@php($formTabs = $section !== 'system' && ($section !== 'payments' || $gateways))
@php($tabName = strtolower($sections[$section] ?? 'settings'))
<div class="form-col">
@if ($formTabs && $errors->any())
    <div class="alert" role="alert"><b>{{ $errors->count() }} {{ \Illuminate\Support\Str::plural('field needs', $errors->count()) }} attention. Nothing was saved.</b>
        <ul class="m-0">@foreach ($errors->keys() as $k)@php($parts = explode('.', $k))<li><a class="link-underline" href="#f-{{ preg_replace('/[^a-z0-9]+/i', '-', array_shift($parts).implode('', array_map(fn ($p) => "[$p]", $parts))) }}">{{ $errors->first($k) }}</a></li>@endforeach</ul></div>
@endif

@if ($section === 'payments')
    @if (! $gateways)
        <x-card><x-empty icon="credit-card" title="No payment gateways installed" text="Payment providers are plugins. Enable Stripe or PayPal under Plugins to accept online payments. Until then you can record bank transfers by hand on each invoice."><a class="btn primary" href="{{ route('admin.plugins.index') }}">Open plugins</a></x-empty></x-card>
    @else
        <form method="POST" action="{{ route('admin.settings.update', 'payments') }}" class="card setting" data-dirty-form novalidate>
            @csrf @method('PUT')
            <div class="setting-h"><h2>Payment gateways</h2><p>Choose how customers pay online. Each gateway is a plugin.</p></div>
            <div class="setting-b">
            @foreach ($gateways as $key => $gw)
                @php($on = \App\Support\Settings::bool("gateway.$key.enabled"))
                <div class="row spread"><h3>{{ $gw->label() }}</h3>
                    @if ($gw->isConfigured() && $on)<span class="pill good">Live</span>@elseif ($on)<span class="pill warn">Needs credentials</span>@else<span class="pill">Off</span>@endif</div>
                @if($gw->description())<p class="muted small m-0">{{ $gw->description() }}</p>@endif
                <x-system-field :name="'gateways['.$key.'][enabled]'" label="Accept payments with {{ $gw->label() }}" type="checkbox" :value="$on" />
                <x-system-field :name="'gateways['.$key.'][title]'" label="Name shown to customers" :optional="true" :value="\App\Support\Settings::get('gateway.'.$key.'.title')" :placeholder="$gw->label()" help="Shown at checkout. Leave blank to use {{ $gw->label() }}." />
                <x-system-fields :fields="$gw->fields()" :values="\App\Support\Settings::group('gateway.'.$key.'.')" :prefix="'gateways['.$key.']'" />
                <div class="notice copy-row"><span>Webhook URL to add in {{ $gw->label() }}:</span><code class="break-all">{{ route('webhooks.gateway', $key) }}</code><button type="button" class="btn sm" data-copy="{{ route('webhooks.gateway', $key) }}">Copy</button></div>
            @endforeach
            </div>
            <div class="setting-f" data-savebar><span class="help" data-savebar-text>Saved secrets are never shown again. Use Replace to change one.</span>
                <div class="row"><button class="btn sm ghost" type="button" data-savebar-discard hidden>Discard</button><button class="btn sm primary" type="submit">Save payment settings</button></div></div>
        </form>
    @endif
@elseif ($section === 'system')
    <section class="card setting">
        <div class="setting-h"><h2>This installation</h2><p>Version and server details, useful when asking for support.</p></div>
        <div class="setting-b"><dl class="kv wide">@foreach ($system as $k => $v)@if (! in_array($k, ['cron_url', 'cli_cron']))<dt>{{ $k }}</dt><dd class="break-all">{{ $v }}</dd>@endif @endforeach</dl></div>
    </section>
    <section class="card setting">
        <div class="setting-h"><h2>Scheduled tasks</h2><p>Renewal invoices, reminders, suspensions and queued jobs run from one cron entry. Use the command line if your host allows it, otherwise the web URL.</p></div>
        <div class="setting-b">
            <h3>Command-line cron (recommended)</h3>
            <div class="secret-box"><span class="grow">{{ $system['cli_cron'] }}</span><button type="button" class="btn sm" data-copy="{{ $system['cli_cron'] }}">Copy</button></div>
            <h3>Web cron (shared hosting without command-line cron)</h3>
            @if ($system['cron_url'])
                <div class="secret-box"><span class="grow">{{ $system['cron_url'] }}</span><button type="button" class="btn sm" data-copy="{{ $system['cron_url'] }}">Copy</button></div>
                <p class="help m-0">Call this URL every minute, for example with <code>wget -q -O /dev/null "URL"</code> in cPanel. Anyone with the URL can start a run, so keep it private.</p>
            @else<p class="muted m-0">No web cron URL yet.</p>@endif
            <form method="POST" action="{{ route('admin.settings.cron-token') }}" data-confirm="This invalidates the current cron URL. Update your cron job afterwards." data-confirm-label="Regenerate" data-danger>@csrf<button class="btn sm" type="submit">{{ $system['cron_url'] ? 'Regenerate URL' : 'Generate URL' }}</button></form>
        </div>
        <div class="setting-f"><span class="help">Want to check it works? Run everything once now.</span>
            <form method="POST" action="{{ route('admin.settings.run') }}">@csrf<button class="btn sm" type="submit">Run billing tasks now</button></form></div>
    </section>
    @include('admin.settings._pdf-fonts')
@else
    <form method="POST" action="{{ route('admin.settings.update', $section) }}" class="card setting" data-dirty-form novalidate>
        @csrf @method('PUT')
        <div class="setting-h"><h2>{{ $sections[$section] ?? 'Settings' }}</h2><p>{{ $groups[0]['description'] ?? '' }}</p></div>
        <div class="setting-b">
            @foreach ($groups as $g)
                @if (! $loop->first)<h3>{{ $g['title'] }}</h3><p class="help m-0">{{ $g['description'] }}</p>@endif
                <x-system-fields :fields="$g['fields']" :values="$values" prefix="s" />
                @if (($g['test'] ?? null) === 'email')
                    <div class="notice"><div>Sends to {{ auth()->user()->email }} using the saved settings. Unsaved edits are not used.</div><button class="btn sm" type="submit" form="email-test"><x-icon name="mail" :size="14" /> Send test email</button></div>
                @endif
            @endforeach
        </div>
        <div class="setting-f" data-savebar><span class="help" data-savebar-text>Changes apply to the whole {{ $tabName }} tab.</span>
            <div class="row"><button class="btn sm ghost" type="button" data-savebar-discard hidden>Discard</button><button class="btn sm primary" type="submit">Save {{ $tabName }} settings</button></div></div>
    </form>
    @if ($section === 'email')<form id="email-test" method="POST" action="{{ route('admin.settings.email.test') }}">@csrf</form>@endif
@endif
</div>
@endsection
