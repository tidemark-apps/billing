@php($company = \App\Support\Settings::get('company.name') ?: config('billing.name'))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>@include('partials.head', ['title' => trim($__env->yieldContent('title')) ?: null])</head>
<body class="auth-body">
<main class="auth-card {{ trim($__env->yieldContent('wide')) ? 'wide' : '' }}">
    <a class="brand big auth-brand" href="{{ url('/') }}">
        @if ($logo = \App\Support\Settings::get('company.logo_url'))<img class="brand-logo" src="{{ $logo }}" alt="{{ $company }}">
        @else<span class="auth-name">{{ $company }}</span>@endif
    </a>
    @if (session('status'))<div class="notice good"><x-icon name="circle-check" :size="16" /><div>{{ session('status') }}</div></div>@endif
    {{-- A summary only for messages the page doesn't already print next to a field. --}}
    @php($body = $__env->yieldContent('content'))
    @php($orphans = collect($errors->all())->reject(fn ($m) => str_contains($body, e($m)))->values())
    @if ($orphans->isNotEmpty())<div class="alert" role="alert">@foreach ($orphans as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    {!! $body !!}
</main>
@if (config('billing.legal'))<div class="legal-links">@foreach (config('billing.legal') as $k => $url)<a href="{{ $url }}" rel="noopener">{{ ucfirst($k) }}</a>@endforeach</div>@endif
<script src="{{ asset('assets/js/app.js') }}?v={{ @filemtime(public_path('assets/js/app.js')) }}" defer></script>
</body>
</html>
