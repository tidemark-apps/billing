@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'options' => [], 'required' => false, 'optional' => false, 'placeholder' => null, 'suffix' => null, 'secretSet' => false, 'id' => null])
{{-- Settings-area field: label above, "(optional)" instead of asterisks, hint, inline error wired with aria, and a "set / replace" pattern for secrets. --}}
@php
    $dot = str_replace(['[', ']'], ['.', ''], rtrim($name, ']'));
    $current = old($dot, $value);
    $id ??= 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name);
    $err = $errors->first($dot);
    $desc = trim(($help ? $id.'-help ' : '').($err ? $id.'-err' : ''));
    $aria = ($desc ? 'aria-describedby='.$desc.' ' : '').($err ? 'aria-invalid=true' : '');
@endphp
@if ($type === 'checkbox')
    <div class="sf">
        <label class="toggle" for="{{ $id }}">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) $current) {{ $attributes }}>
            <span class="track" aria-hidden="true"></span>
            <span><b>{{ $label }}</b>@if($help)<span class="help" id="{{ $id }}-help">{{ $help }}</span>@endif</span>
        </label>
        @if ($err)<span class="field-error" id="{{ $id }}-err">{{ $err }}</span>@endif
    </div>
@else
    <div class="sf {{ $err ? 'has-error' : '' }}" @if ($type === 'password') data-secret @endif>
        <label for="{{ $id }}" class="sf-label"><span>{{ $label }}@if($optional && ! $required) <span class="opt">(optional)</span>@endif</span></label>
        @if ($type === 'password' && $secretSet)
            <div class="secret-state" data-secret-state>
                <span class="pill good">Set</span><span class="dots" aria-hidden="true">••••••••••••</span>
                <button type="button" class="btn sm" data-secret-replace aria-controls="{{ $id }}">Replace</button>
            </div>
            <div class="secret-input hidden" data-secret-input>
                <input id="{{ $id }}" type="password" name="{{ $name }}" value="" disabled autocomplete="new-password" placeholder="Paste the new value" {{ $aria }} {{ $attributes }}>
                <button type="button" class="btn sm ghost" data-secret-cancel>Keep current</button>
            </div>
        @elseif ($type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" @required($required) {{ $aria }} {{ $attributes }}>
                @foreach ($options as $k => $v)<option value="{{ $k }}" @selected((string) $current === (string) $k)>{{ $v }}</option>@endforeach
            </select>
        @elseif ($type === 'textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="3" @required($required) placeholder="{{ $placeholder }}" {{ $aria }} {{ $attributes }}>{{ $current }}</textarea>
        @elseif ($suffix)
            <div class="input-group sf-short"><input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $current }}" @required($required) placeholder="{{ $placeholder }}" {{ $aria }} {{ $attributes }}><span class="addon end">{{ $suffix }}</span></div>
        @else
            <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : $current }}" @required($required) placeholder="{{ $placeholder }}" {{ $aria }} {{ $attributes->class(['sf-short' => $type === 'number']) }}>
        @endif
        @if ($help)<span class="help" id="{{ $id }}-help">{{ $help }}</span>@endif
        @if ($err)<span class="field-error" id="{{ $id }}-err">{{ $err }}</span>@endif
    </div>
@endif
