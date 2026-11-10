@if (\App\Support\Plan::suspended())
    <div class="notice warn" role="alert"><x-icon name="triangle-alert" :size="16" /><div><strong>This account is suspended.</strong> {{ \App\Support\Plan::current()['suspended_message'] ?? 'Contact your provider to restore it.' }}</div></div>
@endif
@if (session('support_session'))
    <div class="notice"><x-icon name="life-buoy" :size="16" /><div>Support session: {{ session('support_session') }} is signed in to this account. Their actions are recorded in the activity log.</div></div>
@endif
