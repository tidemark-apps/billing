@php($o = session('order_placed'))
@if ($o)
<section class="card order-done" role="status" aria-label="Order confirmation">
    <span class="order-done-ico"><x-icon name="circle-check" :size="22" /></span>
    <div class="stack sm">
        <h2 class="m-0">{{ $o['trial'] ? 'Your free trial has started' : 'Order placed' }}</h2>
        @if ($o['trial'])
            <p class="muted m-0">{{ $o['product'] }} is being set up. @if (! empty($o['trial_ends']))You will not be charged before {{ $o['trial_ends'] }}, and you can cancel any time before then.@endif</p>
        @else
            <p class="muted m-0">We created your invoice for {{ $o['product'] }}. Pay it below and your service is set up as soon as the payment arrives.</p>
        @endif
    </div>
</section>
@endif
