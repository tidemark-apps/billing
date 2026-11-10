@php($fonts = app(\App\Services\PdfFonts::class))
@php($have = $fonts->installed())
<section class="card setting">
    <div class="setting-h"><h2>PDF fonts for Chinese, Japanese and Korean</h2><p>Invoice and quote PDFs use a built-in Latin font. Install a free Noto Sans pack to print other scripts. It is a one-time download of about 6 to 10 MB each, checked against a pinned checksum. Other documents are not affected.</p></div>
    <div class="setting-b">
        @error('fonts')<div class="alert" role="alert">{{ $message }}</div>@enderror
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
        <div class="setting-f"><span class="help">Installs Simplified Chinese, Japanese and Korean in one go.</span>
            <form method="POST" action="{{ route('admin.settings.pdf-fonts') }}">@csrf<input type="hidden" name="pack" value="default"><button class="btn sm primary" type="submit">Install all font packs</button></form></div>
    @endif
</section>
