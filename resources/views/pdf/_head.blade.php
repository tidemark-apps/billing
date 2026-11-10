{{-- Title and logo, then "issued to" and the document facts. Expects $company, $docType, $number, $toLabel, $client, $meta (label => value), $stamp, $stampTone, optional $subject. --}}
<table class="head"><tr>
    <td style="width:60%">
        <div class="title">{{ $docType }}</div>
        @if ($stamp)<div class="stamp {{ $stampTone }}">{{ $stamp }}</div>@endif
    </td>
    <td style="width:40%" class="right">
        @if ($company['logo'])<img class="logo" src="{{ $company['logo'] }}" alt="">@else<span class="co-name">{{ $company['name'] }}</span>@endif
    </td>
</tr></table>
<table class="info"><tr>
    <td style="width:60%">
        <b>{{ $toLabel }}</b>
        <div class="row">{{ $client->displayName() }}</div>
        @foreach ($client->addressLines() as $line)<div class="row">{{ $line }}</div>@endforeach
        @if ($client->tax_id)<div class="row">Tax ID: {{ $client->tax_id }}</div>@endif
        @if (! empty($subject))<div class="row" style="margin-top:8px"><b>{{ $subject }}</b></div>@endif
    </td>
    <td style="width:40%">
        <b>{{ $docType }} No. {{ $number }}</b>
        @foreach ($meta as $k => $v)<div class="row">{{ $k }}: {{ $v }}</div>@endforeach
    </td>
</tr></table>
