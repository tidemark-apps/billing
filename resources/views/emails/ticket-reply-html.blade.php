@php
    $link = fn (string $t) => preg_replace('~(https?://[^\s<]+?)([.,;:!?)]*)(?=\s|<|$)~i', '<a href="$1" style="color:#1155cc">$1</a>$2', e(trim($t)));
    $dotted = 'border:0;border-top:1px dotted #c2c8cc;margin:20px 0';
@endphp
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $ticket->subject }}</title></head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:14px;line-height:22px;color:#2f3941">
<div style="padding:16px 20px;max-width:640px">
<p style="margin:0 0 16px;color:#b2b2b2">{{ \App\Services\Support\InboundEmail::MARKER }}</p>
<p style="margin:0">Your request ({{ $ticket->number }}) has been updated.@if ($ticket->is_locked) This ticket is locked, so replies are closed.@else To add additional comments, reply to this email.@endif</p>
@foreach ($thread as $m)
<hr style="{{ $dotted }}">
<p style="margin:0;font-size:16px;line-height:24px"><b>{{ $m->author_name ?: $m->user?->name ?: 'Customer' }}</b>@if ($m->fromStaff()) <span style="color:#2f3941">({{ $company }})</span>@endif</p>
<p style="margin:0 0 12px;color:#b2b2b2">{{ $m->created_at->format('M j, Y, H:i T') }}</p>
<div style="white-space:pre-wrap;margin:0">{!! $link($m->body) !!}</div>
@if ($loop->first && $attachments > 0)<p style="margin:12px 0 0;color:#68737d">{{ $attachments }} attachment{{ $attachments === 1 ? '' : 's' }}@if ($ticketUrl): <a href="{{ $ticketUrl }}" style="color:#1155cc">open the ticket to download {{ $attachments === 1 ? 'it' : 'them' }}</a>.@else not shown here.@endif</p>@endif
@endforeach
<hr style="border:0;border-top:1px solid #e9ebed;margin:28px 0 12px">
<p style="margin:0;font-size:13px;color:#b2b2b2">This email is a service from {{ $company }}.@if ($ticketUrl) <a href="{{ $ticketUrl }}" style="color:#68737d">View it online</a>@endif</p>
</div></body></html>
