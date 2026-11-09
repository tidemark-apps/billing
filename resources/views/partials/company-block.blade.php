<div>
    <b style="font-size:15px">{{ \App\Support\Settings::get('company.name') ?: config('billing.name') }}</b>
    <div class="muted small" style="white-space:pre-line">{{ \App\Support\Settings::get('company.address') }}</div>
    @if ($e = \App\Support\Settings::get('company.email'))<div class="muted small">{{ $e }}</div>@endif
    @if ($t = \App\Support\Settings::get('company.tax_id'))<div class="muted small">Tax ID: {{ $t }}</div>@endif
</div>
