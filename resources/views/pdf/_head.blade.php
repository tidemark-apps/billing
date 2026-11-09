{{-- Company block on the left, document title, status stamp and dates on the right. Expects $company, $title, $stamp, $stampTone, $meta (label => value). --}}
<table class="head"><tr>
    <td style="width:55%">
        @if ($company['logo'])<img class="logo" src="{{ $company['logo'] }}" alt=""><br>@endif
        <span class="co-name">{{ $company['name'] }}</span>
        @if ($company['address'] !== '')<div class="muted small pre">{{ $company['address'] }}</div>@endif
        @if ($company['email'] !== '')<div class="muted small">{{ $company['email'] }}</div>@endif
        @if ($company['tax_id'] !== '')<div class="muted small">Tax ID: {{ $company['tax_id'] }}</div>@endif
    </td>
    <td style="width:45%" class="right">
        <div class="title">{{ $title }}</div>
        @if ($stamp)<div class="stamp {{ $stampTone }}">{{ $stamp }}</div>@endif
        <table class="meta" style="margin-left:auto">
            @foreach ($meta as $k => $v)<tr><td class="k right">{{ $k }}</td><td class="right">{{ $v }}</td></tr>@endforeach
        </table>
    </td>
</tr></table>
