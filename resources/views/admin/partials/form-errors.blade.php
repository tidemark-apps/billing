{{-- Summary of validation errors after a failed save. Entries survive via old(). --}}
<div class="notice danger" role="alert" id="form-summary" @if (! $errors->any()) hidden @endif>
    <x-icon name="triangle-alert" :size="16" />
    <div><b><span data-count>{{ $errors->count() }} {{ $errors->count() === 1 ? 'field needs' : 'fields need' }}</span> attention.</b> Nothing was saved. Fix the marked fields and save again.
        @if ($errors->any())<ul class="err-list">@foreach ($errors->keys() as $k)<li>@if (preg_match('/^items\.(\d+)\./', $k, $m))Line {{ $m[1] + 1 }}: @endif{{ $errors->first($k) }}</li>@endforeach</ul>@endif</div>
</div>
