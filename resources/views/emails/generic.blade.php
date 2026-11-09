@php($company = \App\Support\Settings::get('company.name') ?: config('billing.name'))
<!doctype html>
<html><body style="margin:0;background:#fafafa;font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;color:#0a0a0a">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;padding:32px 16px"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e5e5e5;border-radius:10px">
<tr><td style="padding:28px 32px 8px;font-weight:600;font-size:15px;letter-spacing:-0.01em;color:#0b6670">{{ $company }}</td></tr>
<tr><td style="padding:8px 32px 0"><h1 style="margin:0;font-size:22px;line-height:30px;letter-spacing:-0.02em">{{ $heading }}</h1></td></tr>
<tr><td style="padding:12px 32px 4px;font-size:14px;line-height:22px;color:#333">
@foreach ($lines as $line)<p style="margin:0 0 10px">{{ $line }}</p>@endforeach
</td></tr>
@if ($actionUrl)<tr><td style="padding:12px 32px 28px"><a href="{{ $actionUrl }}" style="display:inline-block;background:#0e7c86;color:#ffffff;text-decoration:none;font-weight:500;font-size:14px;padding:10px 18px;border-radius:6px">{{ $actionLabel }}</a></td></tr>@else<tr><td style="padding-bottom:20px"></td></tr>@endif
</table>
<p style="font-size:12px;color:#666;margin:16px 0 0">Sent by {{ $company }}</p>
</td></tr></table></body></html>
