{{-- Company details bottom left, an optional thank-you bottom right. Expects $company; optional $thanks. --}}
<div class="foot"><table><tr>
    <td style="width:60%">
        <div class="name">{{ $company['name'] }}</div>
        @if ($company['address'] !== '')<div class="pre">{{ $company['address'] }}</div>@endif
        @if ($company['email'] !== '')<div>{{ $company['email'] }}</div>@endif
        @if ($company['tax_id'] !== '')<div>Tax ID: {{ $company['tax_id'] }}</div>@endif
    </td>
    <td style="width:40%" class="thanks">{{ $thanks ?? '' }}</td>
</tr></table></div>
