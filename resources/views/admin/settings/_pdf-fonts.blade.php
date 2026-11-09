@php($fonts = app(\App\Services\PdfFonts::class))
@php($have = $fonts->installed())
<x-card title="PDF fonts (Chinese, Japanese, Korean)">
    <p class="muted" style="margin-bottom:12px">Invoice and quote PDFs use a built-in Latin font. To print Chinese, Japanese or Korean text, install the free Noto Sans font packs (SIL Open Font License, one-time download of about 6 to 10 MB each, checked against a pinned checksum). Documents without such text are unaffected.</p>
    @error('fonts')<div class="alert" style="margin-bottom:12px">{{ $message }}</div>@enderror
    <div class="stack sm">
        @foreach ($fonts->packs() as $key => $pack)
            <div class="row spread">
                <div><b>{{ $pack['label'] }}</b> <span class="muted small">{{ $pack['family'] }}, {{ number_format(($pack['size'] ?? 0) / 1048576, 1) }} MB</span></div>
                <form method="POST" action="{{ route('admin.settings.pdf-fonts') }}" class="row">@csrf
                    <input type="hidden" name="pack" value="{{ $key }}">
                    @if (in_array($key, $have, true))
                        <span class="pill good">Installed</span><input type="hidden" name="action" value="remove"><button class="btn sm" type="submit">Remove</button>
                    @else
                        <button class="btn sm" type="submit">Install</button>
                    @endif
                </form>
            </div>
        @endforeach
    </div>
    @if (! $have)
        <x-slot:footer><span>Chinese, Japanese and Korean in one go (Simplified Chinese, Japanese, Korean).</span>
            <form method="POST" action="{{ route('admin.settings.pdf-fonts') }}" onsubmit="this.querySelector('button').disabled = true">@csrf<input type="hidden" name="pack" value="default"><button class="btn sm primary" type="submit">Install font packs</button></form></x-slot:footer>
    @endif
</x-card>
