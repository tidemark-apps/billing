<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{!! ($title ?? null) ? $title.' · ' : '' !!}{{ \App\Support\Settings::get('company.name') ?: config('billing.name') }}</title>
<meta name="robots" content="noindex">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('assets/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('assets/apple-touch-icon.png') }}">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#fafafa" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<script>(function(){try{var t=localStorage.getItem('tidemark.theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}})();</script>
<link rel="preload" href="{{ asset('assets/fonts/geist-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ @filemtime(public_path('assets/app.css')) }}">
