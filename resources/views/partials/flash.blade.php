<div class="toasts" aria-live="polite">
    @if (session('status'))<div class="toast" data-auto role="status"><x-icon name="circle-check" :size="16" /><div class="toast-t">{{ session('status') }}</div></div>@endif
    @if (session('error'))<div class="toast err" role="alert"><x-icon name="circle-alert" :size="16" /><div class="toast-t">{{ session('error') }}</div></div>@endif
</div>
@if ($errors->any())
    <div class="alert" role="alert" style="margin-top:16px"><div><b>Please fix the following:</b><ul style="margin:6px 0 0 18px;padding:0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
@endif
