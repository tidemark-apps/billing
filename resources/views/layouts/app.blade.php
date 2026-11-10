{{-- The signed-in shell for both areas. Set $area to "admin" or "portal" with @extends('layouts.app', ['area' => 'admin']). --}}
@php
    $area ??= auth()->user()?->isStaff() ? 'admin' : 'portal';
    $portal = $area === 'portal';
    $nav = $portal ? [] : \App\Support\Navigation::for($area);
    $menu = $portal ? \App\Support\Navigation::portal() : [];
    $company = \App\Support\Settings::get('company.name') ?: config('billing.name');
    $logo = \App\Support\Settings::get('company.logo_url');
    $user = auth()->user();
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])
    @stack('head')
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="app {{ $portal ? 'is-portal' : 'has-side' }}">
    <header class="topbar">
        <div class="topbar-in">
            <button class="btn ghost icon side-toggle" type="button" aria-label="Menu"><x-icon name="menu" :size="18" /></button>
            <a class="brand" href="{{ route($area === 'admin' ? 'admin.dashboard' : 'portal.dashboard') }}">
                @if ($area === 'portal' && $logo)<img class="brand-logo" src="{{ $logo }}" alt="{{ $company }}">
                @elseif ($area === 'admin')<x-logo :size="26" /><span class="brand-name" style="margin-left:10px">Billing</span>
                @else<span class="brand-name">{{ $company }}</span>@endif
            </a>
            @if ($portal)
            <nav class="pnav" aria-label="Main">
                @foreach ($menu as $m)
                    @if ($m['url'])
                        <a class="pnav-link {{ $m['active'] ? 'on' : '' }}" href="{{ $m['url'] }}" @if($m['active']) aria-current="page" @endif>{{ $m['label'] }}</a>
                    @else
                        <div class="pop-wrap">
                            <button class="pnav-link {{ $m['active'] ? 'on' : '' }}" type="button" data-pop-trigger aria-haspopup="menu" aria-expanded="false">{{ $m['label'] }}<x-icon name="chevron-down" :size="14" /></button>
                            <div class="pop left hidden" role="menu">
                                @foreach ($m['children'] as $c)<a class="item" href="{{ $c['url'] }}"><span>{{ $c['label'] }}</span><x-icon :name="$c['icon']" :size="14" /></a>@endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </nav>
            <div class="crumbs"></div>
            @else
            <div class="crumbs"><span class="crumb-sep" aria-hidden="true">/</span><span class="crumb-cur">{!! trim($__env->yieldContent('title')) ?: e($area === 'admin' ? 'Admin' : 'Client area') !!}</span></div>
            @endif
            <div class="tb-right">
                @if ($user->isStaff() && $area === 'portal')<a class="btn sm" href="{{ route('admin.dashboard') }}">Admin</a>@endif
                @if (session('impersonator_id'))<form method="POST" action="{{ route('admin.impersonate.stop') }}">@csrf<button class="btn sm" type="submit">Stop viewing as client</button></form>@endif
                <div class="pop-wrap">
                    <button class="btn avatar-btn" type="button" data-pop-trigger aria-haspopup="menu" aria-expanded="false" aria-label="Account menu"><span class="avatar">{{ $user->initials() }}</span></button>
                    <div class="pop hidden" role="menu" style="min-width:240px">
                        <div class="who"><b>{{ $user->name }}</b><span class="muted">{{ $user->email }}</span></div>
                        <hr>
                        @if ($portal)
                            <a class="item" href="{{ route('portal.profile') }}"><span>Account details</span><x-icon name="user" :size="14" /></a>
                            <a class="item" href="{{ route('portal.security.index') }}"><span>Security</span><x-icon name="shield-check" :size="14" /></a>
                            @if (Route::has('portal.api-tokens'))<a class="item" href="{{ route('portal.api-tokens') }}"><span>API access</span><x-icon name="key" :size="14" /></a>@endif
                        @endif
                        @if ($area === 'admin' && Route::has('admin.account.security.index'))<a class="item" href="{{ route('admin.account.security.index') }}"><span>Security</span><x-icon name="shield-check" :size="14" /></a>@endif
                        @if ($user->isStaff())
                            <a class="item" href="{{ $area === 'admin' ? route('portal.dashboard') : route('admin.dashboard') }}"><span>{{ $area === 'admin' ? 'Client area' : 'Admin' }}</span><x-icon name="{{ $area === 'admin' ? 'users' : 'shield-check' }}" :size="14" /></a>
                        @endif
                        <hr><div class="grp" id="theme-label">Theme</div>
                        <div class="theme-seg" role="radiogroup" aria-labelledby="theme-label">
                            <button type="button" role="radio" data-theme="light" aria-label="Light"><x-icon name="sun" /></button>
                            <button type="button" role="radio" data-theme="dark" aria-label="Dark"><x-icon name="moon" /></button>
                            <button type="button" role="radio" data-theme="system" aria-label="System"><x-icon name="monitor" /></button>
                        </div>
                        <hr>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="item" type="submit" style="width:100%;background:none;border:0;cursor:pointer"><span>Sign out</span><x-icon name="log-out" :size="14" /></button></form>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <div class="app-body">
        @if ($portal)
        <div class="pcrumb"><div class="pcrumb-in"><a href="{{ route('portal.dashboard') }}">Client area</a>@if (! request()->is('portal'))<span aria-hidden="true">/</span><span class="pcrumb-cur">{!! trim($__env->yieldContent('title')) !!}</span>@endif</div></div>
        @else
        <aside class="side" aria-label="Main">
            <nav class="side-scroll">
                @foreach ($nav as $group => $items)
                    <div class="side-grp">{{ $group }}</div>
                    @foreach ($items as $i)
                        <a class="side-link {{ $i['active'] ? 'on' : '' }}" href="{{ $i['url'] }}" title="{{ $i['label'] }}" @if($i['active']) aria-current="page" @endif><x-icon :name="$i['icon']" :size="16" /><span>{{ $i['label'] }}</span>@if (! empty($i['badge']))<span class="pill warn" style="margin-left:auto">{{ $i['badge'] }}</span>@endif</a>
                    @endforeach
                @endforeach
            </nav>
            <div class="side-foot"><button class="side-collapse" type="button" aria-pressed="false" aria-label="Collapse sidebar"><x-icon name="panel-left" :size="16" /><span>Collapse</span></button></div>
        </aside>
        <div class="side-scrim" aria-hidden="true"></div>
        @endif
        <main class="app-main" id="main" tabindex="-1">
            <div class="main">
                @include('partials.flash')
                @include('partials.host-notices')
                @yield('content')
                <p class="muted small" style="text-align:center;padding-top:24px">Powered by <a href="https://www.tidemarkanalytics.com" rel="noopener" style="text-decoration:underline;text-underline-offset:3px">Tidemark Billing</a> v{{ config('billing.version') }}</p>
            </div>
        </main>
    </div>
</div>
<script src="{{ asset('assets/js/app.js') }}?v={{ @filemtime(public_path('assets/js/app.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
