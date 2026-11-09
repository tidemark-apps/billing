<x-card title="Software licence">
    @if (! $license)<p class="muted">No licence has been issued for this service yet.</p>@else
        <div class="secret-box"><span class="grow">{{ $license->key }}</span><button class="btn sm" type="button" data-copy="{{ $license->key }}">Copy</button></div>
        <p class="small muted" style="margin-top:8px">{{ ucfirst($license->effectiveStatus()) }} · {{ $license->activations->count() }} of {{ $license->max_activations }} activations · <a href="{{ route('admin.license-server.show', $license) }}" style="text-decoration:underline">Manage licence</a></p>
    @endif
</x-card>
