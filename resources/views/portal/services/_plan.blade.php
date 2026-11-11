    @if ($preview)
        <p class="muted small mb-16">Here is what changes. Nothing happens until you confirm.</p>
        @include('portal.services._plan-preview', ['preview' => $preview, 'staff' => false])
        <form method="POST" action="{{ route('portal.services.plan-change.store', $service) }}" class="row mt-16">@csrf
            <input type="hidden" name="plan" value="{{ $selected['plan'] }}">
            <button class="btn primary" type="submit">Confirm change</button><a class="btn" href="{{ route('portal.services.show', $service) }}">Back</a>
        </form>
    @else
        <form method="POST" action="{{ route('portal.services.plan-change.preview', $service) }}" class="stack">@csrf
            <label><span>Switch to</span>
                <select name="plan" required>
                    @foreach ($planOptions->groupBy(fn ($o) => $o['product']->name) as $name => $opts)
                        <optgroup label="{{ $name }}">@foreach ($opts as $o)<option value="{{ $o['product']->id }}:{{ $o['cycle']->value }}">{{ $o['cycle']->label() }} · {{ money($o['amount'], $service->currency) }} {{ $o['cycle']->suffix() }}</option>@endforeach</optgroup>
                    @endforeach
                </select>
            </label>
            <p class="help">Upgrades start today and you pay only the difference for the rest of the period. Downgrades apply at your next renewal.</p>
            <div><button class="btn" type="submit">Preview change</button></div>
        </form>
    @endif
