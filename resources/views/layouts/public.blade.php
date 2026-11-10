{{-- Public, signed-out pages: store catalogue, invoice and quote links. Centered content under a light top bar. --}}
@php
    $company = \App\Support\Settings::get('company.name') ?: config('billing.name');
    $logo = \App\Support\Settings::get('company.logo_url');
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])@stack('head')</head>
<body>
<header class="topbar"><div class="topbar-in">
    <a class="brand" href="{{ url('/') }}">@if ($logo)<img class="brand-logo" src="{{ $logo }}" alt="{{ $company }}">@else<span class="brand-name">{{ $company }}</span>@endif</a>
    <div class="crumbs"></div>
    <div class="tb-right">
        @auth<a class="btn sm" href="{{ route('portal.dashboard') }}">Client area</a>
        @else<a class="btn sm ghost" href="{{ route('login') }}">Sign in</a>@if (config('billing.registration') && \App\Support\Settings::bool('general.registration', true))<a class="btn sm primary" href="{{ route('register') }}">Create account</a>@endif @endauth
    </div>
</div></header>
<main class="main" id="main">
    @include('partials.flash')
    @yield('content')
</main>
<div class="pubfoot"><a href="https://www.tidemarkanalytics.com" rel="noopener">Powered by Tidemark Billing</a></div>
<script src="{{ asset('assets/js/app.js') }}?v={{ @filemtime(public_path('assets/js/app.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
