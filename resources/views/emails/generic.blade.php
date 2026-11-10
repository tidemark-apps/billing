@php($company = \App\Support\Settings::get('company.name') ?: config('billing.name'))
@php($brand = \App\Support\Brand::light())
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>{{ $heading }}</title></head><body style="margin:0;background:#fafafa;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#0a0a0a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:10px">
<tr><td style="padding:28px 32px 8px;font-weight:600;font-size:15px;letter-spacing:-0.01em;color:{{ $brand['ink'] }}">{{ $company }}</td></tr>
<tr><td style="padding:8px 32px 0"><h1 style="margin:0;font-size:22px;line-height:30px;letter-spacing:-0.02em">{{ $heading }}</h1></td></tr>
<tr><td style="padding:12px 32px 4px;font-size:14px;line-height:22px;color:#333">
@foreach ($lines as $line)<p style="margin:0 0 10px;white-space:pre-line">{{ $line }}</p>@endforeach
</td></tr>
@if ($actionUrl)<tr><td style="padding:12px 32px 8px"><a href="{{ $actionUrl }}" style="display:inline-block;background:{{ $brand['brand'] }};color:{{ $brand['foreground'] }};text-decoration:none;font-weight:500;font-size:14px;padding:10px 18px;border-radius:6px">{{ $actionLabel }}</a></td></tr><tr><td style="padding:8px 32px 28px;font-size:12px;line-height:18px;color:#666666">If the button does not work, copy this link into your browser:<br><a href="{{ $actionUrl }}" style="color:{{ $brand['ink'] }};word-break:break-all">{{ $actionUrl }}</a></td></tr>@else<tr><td style="padding-bottom:20px"></td></tr>@endif
</table>
<p style="font-size:12px;color:#666;margin:16px 0 0">Sent by {{ $company }}</p>
</td></tr></table></body></html>
