<x-card title="Your licence">
    @if (! $license)<p class="muted">Your licence key will appear here once your service is active.</p>@else
        <div class="secret-box"><span class="grow">{{ $license->key }}</span><button class="btn sm" type="button" data-copy="{{ $license->key }}">Copy</button></div>
        <p class="small muted" style="margin:8px 0">Status: <b>{{ ucfirst($license->effectiveStatus()) }}</b> · {{ $license->activations->count() }} of {{ $license->max_activations }} activations used</p>
        @if ($license->activations->isNotEmpty())
            <div class="tscroll"><table class="t"><thead><tr><th>{{ ['domain' => 'Domain', 'ip' => 'IP address'][$license->binding] ?? 'Instance' }}</th><th>Last seen</th><th></th></tr></thead><tbody>
                @foreach ($license->activations as $a)<tr><td class="mono">{{ $a->identifier }}</td><td>{{ $a->last_seen_at?->diffForHumans() }}</td>
                    <td class="right"><form method="POST" action="{{ route('portal.license-server.activations.destroy', $a->id) }}" data-confirm="Remove this activation? You can activate it again elsewhere.">@csrf @method('DELETE')<button class="btn sm ghost danger" type="submit">Remove</button></form></td></tr>@endforeach
            </tbody></table></div>
        @endif
    @endif
</x-card>
