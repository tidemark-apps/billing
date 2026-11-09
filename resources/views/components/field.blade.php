@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'help' => null, 'options' => [], 'required' => false, 'placeholder' => null, 'prefix' => null, 'id' => null])
@php
    $dot = str_replace(['[', ']'], ['.', ''], rtrim($name, ']'));
    $current = old($dot, $value);
    $id ??= 'f-'.preg_replace('/[^a-z0-9]+/i', '-', $name);
@endphp
@if ($type === 'checkbox')
    <label class="toggle" for="{{ $id }}">
        <input type="hidden" name="{{ $name }}" value="0">
        <input type="checkbox" role="switch" id="{{ $id }}" name="{{ $name }}" value="1" @checked((bool) $current) {{ $attributes }}>
        <span class="track" aria-hidden="true"></span>
        <span><b>{{ $label }}</b>@if($help)<span class="help">{{ $help }}</span>@endif</span>
    </label>
@else
    <label for="{{ $id }}">
        @if ($label)<span>{{ $label }}@if($required) <span class="muted" aria-hidden="true">*</span>@endif</span>@endif
        @if ($type === 'select')
            <select id="{{ $id }}" name="{{ $name }}" @required($required) {{ $attributes }}>
                @foreach ($options as $k => $v)<option value="{{ $k }}" @selected((string) $current === (string) $k)>{{ $v }}</option>@endforeach
            </select>
        @elseif ($type === 'textarea')
            <textarea id="{{ $id }}" name="{{ $name }}" rows="4" @required($required) placeholder="{{ $placeholder }}" {{ $attributes }}>{{ $current }}</textarea>
        @elseif ($prefix)
            <div class="input-group"><span class="addon">{{ $prefix }}</span><input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : $current }}" @required($required) placeholder="{{ $placeholder }}" {{ $attributes }}></div>
        @else
            <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : $current }}" @required($required) placeholder="{{ $placeholder }}" {{ $attributes }}>
        @endif
        @if ($help)<span class="help">{{ $help }}</span>@endif
        @error($dot)<span class="field-error">{{ $message }}</span>@enderror
    </label>
@endif
