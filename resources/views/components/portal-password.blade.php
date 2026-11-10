@props(['name' => 'password', 'label' => 'Password', 'help' => null, 'required' => true, 'autocomplete' => 'current-password', 'id' => null])
{{-- Password input with a show/hide toggle and an inline error, for auth and profile forms. --}}
@php
    $id ??= 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name);
    $err = $errors->first($name);
@endphp
<label for="{{ $id }}">
    <span>{{ $label }}</span>
    <span class="pw-wrap">
        <input id="{{ $id }}" type="password" name="{{ $name }}" @required($required) autocomplete="{{ $autocomplete }}" autocapitalize="none" spellcheck="false" @if ($err) aria-invalid="true" aria-describedby="{{ $id }}-err" @endif {{ $attributes }}>
        <button class="pw-toggle" type="button" aria-controls="{{ $id }}" aria-pressed="false" data-pw-toggle>Show</button>
    </span>
    @if ($help)<span class="help">{{ $help }}</span>@endif
    @if ($err)<span class="field-error" id="{{ $id }}-err">{{ $err }}</span>@endif
</label>
@once
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-pw-toggle]'); if (!b) return;
    e.preventDefault();
    var i = document.getElementById(b.getAttribute('aria-controls')); if (!i) return;
    var show = i.type === 'password'; i.type = show ? 'text' : 'password';
    b.textContent = show ? 'Hide' : 'Show'; b.setAttribute('aria-pressed', show ? 'true' : 'false');
});
</script>
@endonce
